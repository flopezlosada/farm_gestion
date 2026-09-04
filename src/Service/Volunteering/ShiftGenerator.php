<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\BasketRepository;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Convierte la receta de repetición de una tarea en turnos de verdad.
 *
 * IDEMPOTENTE. Se puede llamar mil veces: crea los turnos que faltan y no toca
 * los que ya están, ni resucita los anulados. Eso es lo que permite que la
 * receta se pueda editar, que el horizonte se extienda con un cron y que el
 * festivo se arregle anulando un turno — la pasada siguiente no lo vuelve a
 * crear porque la fila sigue ahí.
 *
 * HORIZONTE RODANTE ({@see self::HORIZON_DAYS}). No se materializa la serie
 * entera hasta la fecha final: se llega hasta unos meses vista y el resto lo va
 * abriendo el cron. Si no fuera así, "sacar al perro, mañana y tarde, hasta fin
 * de año" nacería con setecientas treinta filas de golpe, la ficha de la tarea
 * sería inmanejable y el primer dedazo en la fecha final llenaría la tabla. Con
 * horizonte, la tarea vive con doscientos y pico turnos como mucho.
 *
 * NO CONFUNDIR CON MATERIALIZAR TARDE EL REPARTO. Aquí los turnos SÍ son filas
 * reales, y tienen que serlo: llevan inscripciones, asistencia y horas
 * imputadas. Lo que se aplaza es cuándo se crean, no si existen.
 *
 * LAS FECHAS DE REPARTO SE PREGUNTAN AL CALENDARIO, no se calculan cada siete
 * días: el reparto cae los días que ese punto reparte de verdad, según su
 * cadencia y sus excepciones. {@see NodeDeliveryDate} es el punto único de
 * verdad de eso, y preguntárselo es la diferencia entre unas fechas que ya
 * vienen bien y cincuenta y dos turnos que alguien repasa a mano.
 */
class ShiftGenerator
{
    /**
     * Hasta cuántos días vista se materializan turnos en una pasada. Cuatro
     * meses: cubre de sobra lo que alguien necesita ver y planificar, y deja el
     * volumen de filas en algo que una ficha puede enseñar.
     */
    public const HORIZON_DAYS = 120;

    /**
     * Tope duro de turnos creados en una sola pasada. Con el horizonte de 120
     * días, dos tramos diarios dan 240; el resto de margen es para tramos
     * múltiples. Por encima de esto lo más probable es un error de dedo.
     */
    public const MAX = 400;

    /**
     * Hora del turno cuando la receta no trae franjas: medianoche, que es como
     * las pantallas entienden «sin hora» y lo callan (vol.shift_when y las
     * tarjetas). Antes eran las nueve, que se enseñaban como una hora real y
     * hacían pensar que había que estar allí a las nueve.
     */
    private const DEFAULT_TIME = '00:00';

    /** Cómo se nombra cada posición del mes al pedírsela a PHP. */
    private const ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BasketRepository $baskets,
        private readonly NodeDeliveryDate $deliveryDate,
    ) {
    }

    /**
     * Crea los turnos que falten de esta tarea y devuelve los nuevos.
     *
     * NO PERSISTE NI HACE FLUSH: engancha los turnos a la tarea (que cascadea
     * persist) y deja el flush a quien orquesta, para que crear la tarea y sus
     * turnos sea una sola transacción y no queden tareas sin turnos si algo
     * falla en medio.
     *
     * @param VolunteerOffer          $offer la tarea con su receta
     * @param \DateTimeInterface|null $now   desde cuándo generar; por defecto, ahora
     *
     * @return list<VolunteerShift> los turnos nuevos, ya enganchados a la tarea
     */
    public function generate(VolunteerOffer $offer, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime();

        $moments = $this->moments($offer, $now);
        if ([] === $moments) {
            return [];
        }

        // Los que ya existen, de una sola vez y en memoria. Con una consulta por
        // momento serían cientos de idas y venidas para una tarea diaria.
        $taken = [];
        foreach ($offer->getShifts() as $shift) {
            $startsAt = $shift->getStartsAt();
            if (null !== $startsAt) {
                $taken[$startsAt->format('Y-m-d H:i')] = true;
            }
        }

        $created = [];
        foreach ($moments as [$start, $end]) {
            $key = $start->format('Y-m-d H:i');
            if (isset($taken[$key])) {
                continue;
            }

            $shift = (new VolunteerShift())
                ->setStartsAt($start)
                ->setEndsAt($end);

            $offer->addShift($shift);
            $this->em->persist($shift);

            $taken[$key] = true;
            $created[] = $shift;
        }

        return $created;
    }

    /**
     * Pone los turnos de la tarea de acuerdo con su receta: crea los que faltan
     * y retira los que la receta ya no dicta.
     *
     * Es lo que se llama al guardar la tarea, y hace falta porque la receta se
     * edita: quien cambia "sábados" por "domingos" espera que los sábados que
     * quedaban dejen de estar, no que convivan los dos.
     *
     * NUNCA RETIRA UN TURNO CON GENTE APUNTADA. Se devuelve en `kept` para que
     * la pantalla lo diga: hay alguien contando con ese día, y borrarlo en
     * silencio dejaría a esa persona apuntada a algo que ya no existe. Quien
     * quiera quitarlo lo anula a mano, que es el gesto que sí avisa.
     *
     * NI UNO QUE HAYA TOCADO UNA PERSONA ({@see VolunteerShift::isManual()}).
     * Éstos no se cuentan ni se avisan: no son un conflicto con la receta, son
     * una decisión que le gana.
     *
     * Tampoco toca nada FUERA de la ventana que la receta alcanza en esta
     * pasada: los turnos más allá del horizonte —o los que alguien añadió a
     * mano para un día que la receta no cubre— no son basura, son decisiones.
     *
     * @param VolunteerOffer          $offer la tarea con su receta
     * @param \DateTimeInterface|null $now   momento de referencia; por defecto, ahora
     *
     * @return array{created: list<VolunteerShift>, removed: int, kept: list<VolunteerShift>}
     */
    public function sync(VolunteerOffer $offer, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime();

        $moments = $this->moments($offer, $now);

        $wanted = [];
        foreach ($moments as [$start]) {
            $wanted[$start->format('Y-m-d H:i')] = true;
        }

        // Sólo se juzga lo que cae dentro de lo que la receta alcanza ahora.
        $window = $this->window($offer, $now);

        $removed = 0;
        $kept = [];

        if (null !== $window) {
            [$from, $until] = $window;

            foreach ($offer->getShifts() as $shift) {
                $startsAt = $shift->getStartsAt();
                if (null === $startsAt || $shift->isPast($now)) {
                    continue;
                }

                // Lo que tocó una persona no se retira nunca. Quien movió el
                // turno de este viernes a las siete porque había asamblea no
                // espera que se borre al corregir una errata en el título de la
                // tarea, y ese borrado no daría ni error ni aviso.
                if ($shift->isManual()) {
                    continue;
                }

                if ($startsAt < $from || $startsAt > $until) {
                    continue;
                }

                if (isset($wanted[$startsAt->format('Y-m-d H:i')])) {
                    continue;
                }

                if ([] !== $shift->getCommittedSignups()) {
                    $kept[] = $shift;

                    continue;
                }

                $offer->removeShift($shift);
                $this->em->remove($shift);
                ++$removed;
            }
        }

        return [
            'created' => $this->generate($offer, $now),
            'removed' => $removed,
            'kept' => $kept,
        ];
    }

    /**
     * Los momentos exactos que dicta la receta: cada fecha cruzada con cada
     * tramo horario.
     *
     * El cruce es lo que resuelve "mañana y tarde": dos tramos por fecha son dos
     * turnos, con la misma tarea detrás y gente distinta apuntada en cada uno.
     *
     * @param VolunteerOffer          $offer la tarea con su receta
     * @param \DateTimeInterface|null $now   desde cuándo mirar; por defecto, ahora
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable|null}> pares inicio/fin, ordenados
     */
    public function moments(VolunteerOffer $offer, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime();

        $window = $this->window($offer, $now);
        if (null === $window) {
            return [];
        }

        [$from, $until] = $window;

        $dates = match ($offer->getRepeatType()) {
            VolunteerOffer::REPEAT_ONCE => $this->onceDates($offer, $from, $until),
            VolunteerOffer::REPEAT_WEEKLY => $this->weeklyDates($offer, $from, $until),
            VolunteerOffer::REPEAT_MONTHLY => $this->monthlyDates($offer, $from, $until),
            VolunteerOffer::REPEAT_DELIVERY => $this->deliveryDates($offer, $from, $until),
            default => [],
        };

        $moments = [];
        foreach ($dates as $date) {
            foreach ($this->timeSlots($offer) as [$startTime, $endTime]) {
                $start = $this->at($date, $startTime);

                // El tramo que cruza la medianoche ("cerrar el invernadero de
                // 23:00 a 00:30") acaba al día siguiente. Sin esto, el fin
                // quedaría antes del principio y la duración saldría negativa.
                $end = null === $endTime ? null : $this->at($date, $endTime);
                if (null !== $end && $end < $start) {
                    $end = $end->modify('+1 day');
                }

                $moments[] = [$start, $end];
            }
        }

        usort($moments, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return \array_slice($moments, 0, self::MAX);
    }

    /**
     * La ventana en la que hay que generar: desde el día de arranque de la
     * receta —o hoy, si ya empezó— hasta lo que llegue antes, el fin de la
     * receta o el horizonte.
     *
     * Devuelve null cuando no hay nada que generar: sin fecha de arranque, o con
     * la receta ya terminada.
     *
     * @param VolunteerOffer     $offer la tarea
     * @param \DateTimeInterface $now   momento de referencia
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null desde/hasta, o null
     */
    private function window(VolunteerOffer $offer, \DateTimeInterface $now): ?array
    {
        $start = $offer->getRepeatFrom();
        if (null === $start) {
            return null;
        }

        $today = \DateTimeImmutable::createFromInterface($now)->setTime(0, 0);
        $from = \DateTimeImmutable::createFromInterface($start)->setTime(0, 0);

        // Nunca se generan turnos hacia atrás: al ampliar la serie en noviembre
        // no se pueden inventar los viernes de septiembre a los que nadie pudo
        // apuntarse. La excepción es la tarea que se crea hoy para hoy mismo, y
        // ésa cae dentro porque el arranque es el día, no la hora.
        if ($from < $today) {
            $from = $today;
        }

        $horizon = $today->modify(sprintf('+%d days', self::HORIZON_DAYS))->setTime(23, 59, 59);

        $until = $offer->getRepeatUntil();
        $until = null === $until
            ? $horizon
            : \DateTimeImmutable::createFromInterface($until)->setTime(23, 59, 59);

        // El último día cuenta entero: quien escribe "hasta el 31 de diciembre"
        // quiere el turno del 31, no todo lo anterior a las 00:00 de ese día.
        if ($until > $horizon) {
            $until = $horizon;
        }

        return $until < $from ? null : [$from, $until];
    }

    /**
     * Los tramos horarios de la receta, normalizados. Si no hay ninguno, uno a
     * medianoche sin hora de fin: un turno de todo el día, que las pantallas
     * enseñan sin hora. Mejor eso que ninguno, que dejaría la tarea publicada
     * y sin nada a lo que apuntarse.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return list<array{0: string, 1: string|null}> pares inicio/fin en "HH:MM"
     */
    private function timeSlots(VolunteerOffer $offer): array
    {
        $slots = [];

        foreach ($offer->getRepeatTimes() as $slot) {
            $start = trim((string) ($slot[0] ?? ''));
            if ('' === $start) {
                continue;
            }

            $end = isset($slot[1]) && '' !== trim((string) $slot[1]) ? trim((string) $slot[1]) : null;
            $slots[] = [$start, $end];
        }

        return [] === $slots ? [[self::DEFAULT_TIME, null]] : $slots;
    }

    /**
     * La fecha única de una tarea que no se repite.
     *
     * @param VolunteerOffer     $offer la tarea
     * @param \DateTimeImmutable $from  desde, incluido
     * @param \DateTimeImmutable $until hasta, incluido
     *
     * @return list<\DateTimeImmutable> una fecha, o ninguna si cae fuera
     */
    private function onceDates(VolunteerOffer $offer, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $day = \DateTimeImmutable::createFromInterface($offer->getRepeatFrom())->setTime(0, 0);

        return ($day >= $from->setTime(0, 0) && $day <= $until) ? [$day] : [];
    }

    /**
     * Las fechas de los días de la semana marcados, cada N semanas.
     *
     * "Cada N semanas" se cuenta desde la semana de arranque de la receta y no
     * desde el día en que se genera: si se contara desde la pasada, extender la
     * serie en noviembre podría desplazar la alternancia de una tarea
     * quincenal — el mismo error que ya se pagó en el calendario de cestas.
     *
     * @param VolunteerOffer     $offer la tarea
     * @param \DateTimeImmutable $from  desde, incluido
     * @param \DateTimeImmutable $until hasta, incluido
     *
     * @return list<\DateTimeImmutable> fechas ordenadas
     */
    private function weeklyDates(VolunteerOffer $offer, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $weekdays = $offer->getRepeatWeekdays();
        if ([] === $weekdays) {
            return [];
        }

        $every = max(1, $offer->getRepeatEvery());
        $anchor = \DateTimeImmutable::createFromInterface($offer->getRepeatFrom())
            ->setTime(0, 0)
            ->modify('monday this week');

        $dates = [];
        $day = $from->setTime(0, 0);

        while ($day <= $until && \count($dates) < self::MAX) {
            if (\in_array((int) $day->format('N'), $weekdays, true)) {
                // Semanas completas entre el ancla y esta semana: si no es
                // múltiplo de la cadencia, esta semana no toca.
                $week = $anchor->diff($day->modify('monday this week'))->days;
                if (0 === intdiv((int) $week, 7) % $every) {
                    $dates[] = $day;
                }
            }

            $day = $day->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Las fechas de una tarea mensual, conservando día de la semana y posición.
     *
     * "El 15 de cada mes" no sirve aquí: el voluntariado se organiza por el día
     * de la semana —el segundo sábado—, porque de eso depende quién puede venir.
     * Sumar 28 días tampoco: deriva unos días cada mes y a los seis ya no cae ni
     * en la misma semana.
     *
     * La posición sale del arranque de la receta y se cuenta igual que en el
     * calendario de reparto ({@see NodeDeliveryDate}): días 1-7 la primera, 8-14
     * la segunda… y si siete días después ya es otro mes, es la ÚLTIMA. Así "el
     * último viernes" sigue siendo el último aunque el mes tenga cinco.
     *
     * @param VolunteerOffer     $offer la tarea
     * @param \DateTimeImmutable $from  desde, incluido
     * @param \DateTimeImmutable $until hasta, incluido
     *
     * @return list<\DateTimeImmutable> fechas ordenadas
     */
    private function monthlyDates(VolunteerOffer $offer, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $anchor = \DateTimeImmutable::createFromInterface($offer->getRepeatFrom())->setTime(0, 0);

        $isLast = $anchor->modify('+7 days')->format('m') !== $anchor->format('m');
        $position = intdiv((int) $anchor->format('j') - 1, 7) + 1;
        $ordinal = $isLast ? 'last' : (self::ORDINALS[$position] ?? 'last');

        // Los días de la semana marcados, o el del arranque si no se marcó
        // ninguno: una mensual sin día es "el segundo martes" del día que se
        // eligió al crearla.
        $weekdays = $offer->getRepeatWeekdays();
        if ([] === $weekdays) {
            $weekdays = [(int) $anchor->format('N')];
        }

        $dates = [];
        $month = $from->modify('first day of this month')->setTime(0, 0);

        while ($month <= $until && \count($dates) < self::MAX) {
            foreach ($weekdays as $weekday) {
                $name = $this->weekdayName($weekday);
                $day = new \DateTimeImmutable(sprintf('%s %s of %s', $ordinal, $name, $month->format('F Y')));
                $day = $day->setTime(0, 0);

                if ($day >= $from->setTime(0, 0) && $day <= $until) {
                    $dates[] = $day;
                }
            }

            $month = $month->modify('first day of next month');
        }

        usort($dates, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $dates;
    }

    /**
     * Las fechas en las que el punto de recogida de la tarea reparte de verdad.
     *
     * La ventana de ciclos se amplía una semana por cada lado antes de filtrar
     * por la fecha física: un nodo puede repartir en un día distinto al del
     * ciclo semanal (Madrid entrega el miércoles lo del viernes), así que
     * recortar por la fecha del ciclo perdería repartos en los bordes. El filtro
     * de verdad es el de después, contra la fecha ya resuelta.
     *
     * NO SIEMPRE ES EL DÍA DEL REPARTO: {@see VolunteerOffer::$repeatOffsetDays}
     * lo corre, porque hay trabajo que cuelga de la entrega y no cae ese día —el
     * montaje de las cestas, sin ir más lejos, que a veces es la tarde anterior—.
     * Ese margen extra de una semana por lado también le da sitio.
     *
     * @param VolunteerOffer     $offer tarea con nodo
     * @param \DateTimeImmutable $from  desde, incluido
     * @param \DateTimeImmutable $until hasta, incluido
     *
     * @return list<\DateTimeImmutable> fechas de trabajo dentro del rango, ya con el desfase
     */
    private function deliveryDates(VolunteerOffer $offer, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $node = $offer->getNode();
        if (null === $node) {
            return [];
        }

        $dates = [];
        $cycles = $this->baskets->findBetweenDates(
            $from->modify('-7 days'),
            $until->modify('+7 days')
        );

        foreach ($cycles as $cycle) {
            $physical = $this->deliveryDate->physicalDateFor($cycle, $node);
            if (null === $physical) {
                continue;
            }

            // El desfase se aplica ANTES de filtrar por la ventana, y ese orden
            // no es cosmético: el montaje de la víspera de un reparto del día 1
            // cae el último día del mes anterior, y filtrando primero se perdería
            // por un día justo en los bordes del rango.
            $day = \DateTimeImmutable::createFromInterface($physical)
                ->setTime(0, 0)
                ->modify(sprintf('%+d days', $offer->getRepeatOffsetDays()));

            if ($day >= $from->setTime(0, 0) && $day <= $until) {
                $dates[] = $day;
            }
        }

        usort($dates, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $dates;
    }

    /**
     * El día del mes vestido con una hora "HH:MM".
     *
     * @param \DateTimeImmutable $date la fecha
     * @param string             $time la hora en "HH:MM"
     *
     * @return \DateTimeImmutable la fecha a esa hora
     */
    private function at(\DateTimeImmutable $date, string $time): \DateTimeImmutable
    {
        [$hours, $minutes] = array_pad(explode(':', $time, 2), 2, '0');

        return $date->setTime((int) $hours, (int) $minutes);
    }

    /**
     * El nombre inglés del día de la semana, que es lo que entiende el parser de
     * fechas de PHP en "second tuesday of March 2026".
     *
     * @param int $weekday día ISO-8601 (1 = lunes … 7 = domingo)
     *
     * @return string el nombre en inglés
     */
    private function weekdayName(int $weekday): string
    {
        // Del 1 (lunes) al 7 (domingo). El 4 de enero de 1970 fue domingo, así
        // que el 4 + N da el día ISO N sin tener que mantener una tabla.
        return (new \DateTimeImmutable(sprintf('1970-01-%02d', 4 + $weekday)))->format('l');
    }
}
