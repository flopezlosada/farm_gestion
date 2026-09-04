<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\BasketRepository;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Volunteering\ShiftGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * De la receta de una tarea a los turnos de verdad.
 *
 * Es la pieza que hace utilizable el módulo: sin ella, "abrir el invernadero
 * sábados y domingos" son dos tareas y "sacar al perro mañana y tarde" son
 * setecientas treinta al año. Aquí se prueba lo que expresa cada receta y, sobre
 * todo, lo que NO toca: la idempotencia y el respeto por lo que ha decidido una
 * persona son las dos propiedades de las que depende que la receta pueda vivir
 * en la tarea.
 *
 * Sin BBDD: el generador sólo hace `persist`/`remove` y engancha turnos a la
 * colección de la tarea, así que un EntityManager doblado basta y el test habla
 * de fechas y no de SQL.
 */
class ShiftGeneratorTest extends TestCase
{
    /** El momento de referencia de todos los casos. Un lunes. */
    private const NOW = '2026-09-07 08:00';

    /**
     * Una tarea de una sola vez: un día, un tramo, un turno.
     */
    public function testUnaVezDaUnSoloTurno(): void
    {
        $offer = $this->offer(VolunteerOffer::REPEAT_ONCE, from: '2026-09-12', times: [['10:00', '13:00']]);

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertCount(1, $created);
        $this->assertSame('2026-09-12 10:00', $created[0]->getStartsAt()->format('Y-m-d H:i'));
        $this->assertSame('2026-09-12 13:00', $created[0]->getEndsAt()->format('Y-m-d H:i'));
    }

    /**
     * DOS TRAMOS SON DOS TURNOS EL MISMO DÍA. Es el caso de sacar al perro, y el
     * que no se podía expresar cuando la tarea llevaba la fecha encima: habría
     * hecho falta crear dos tareas iguales con distinta hora.
     */
    /**
     * Sin franjas, el turno es de todo el día: a medianoche, que es como las
     * pantallas entienden «sin hora» y lo callan. Antes eran las nueve, y se
     * enseñaban como una hora real.
     */
    public function testSinFranjasElTurnoEsDeTodoElDia(): void
    {
        $offer = $this->offer(VolunteerOffer::REPEAT_ONCE, from: '2026-09-12', times: []);

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertCount(1, $created);
        $this->assertSame('2026-09-12 00:00', $created[0]->getStartsAt()->format('Y-m-d H:i'));
        $this->assertNull($created[0]->getEndsAt());
    }

    public function testDosTramosDanDosTurnosElMismoDia(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_ONCE,
            from: '2026-09-12',
            times: [['09:00', '10:00'], ['20:00', '21:00']]
        );

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-12 09:00', '2026-09-12 20:00'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $created)
        );
    }

    /**
     * VARIOS DÍAS DE LA SEMANA A LA VEZ, que es el otro caso que faltaba: abrir
     * el invernadero es sábados y domingos, y es UNA tarea.
     */
    public function testVariosDiasDeLaSemanaEnUnaSolaTarea(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6, 7],
            times: [['09:00', '11:00']]
        );

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-12', '2026-09-13', '2026-09-19', '2026-09-20'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d'), $created)
        );
    }

    /**
     * "Cada dos semanas" se cuenta DESDE LA SEMANA DE ARRANQUE de la receta, no
     * desde el día en que se genera: si se contara desde la pasada, extender la
     * serie en noviembre desplazaría la alternancia de una tarea quincenal — el
     * mismo error que ya se pagó en el calendario de cestas.
     */
    public function testCadaDosSemanasCuentaDesdeElArranque(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-10-11',
            weekdays: [1],
            times: [['18:00', null]],
            every: 2
        );

        $created = $this->generator()->generate($offer, $this->now());

        // Lunes 7 (semana del ancla), 21 de septiembre y 5 de octubre. El 14 y el
        // 28 caen en las semanas impares y se saltan.
        $this->assertSame(
            ['2026-09-07', '2026-09-21', '2026-10-05'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d'), $created)
        );
    }

    /**
     * La mensual conserva el día de la semana y su posición en el mes. "El 15 de
     * cada mes" no sirve: el voluntariado se organiza por el día de la semana,
     * porque de eso depende quién puede venir.
     */
    public function testLaMensualConservaDiaDeLaSemanaYPosicion(): void
    {
        // El 12 de septiembre de 2026 es el segundo sábado.
        $offer = $this->offer(
            VolunteerOffer::REPEAT_MONTHLY,
            from: '2026-09-12',
            until: '2026-11-30',
            times: [['10:00', '13:00']]
        );

        $created = $this->generator()->generate($offer, $this->now());

        $days = array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d D'), $created);

        $this->assertSame(['2026-09-12 Sat', '2026-10-10 Sat', '2026-11-14 Sat'], $days);
    }

    /**
     * EL HORIZONTE RODANTE. Una receta que llega a fin de año no materializa el
     * año entero: se queda en unos meses y el resto lo abre el cron. Sin esto,
     * una tarea diaria nacería con cientos de filas y el primer dedazo en la
     * fecha final llenaría la tabla.
     */
    public function testNoSeMaterializaMasAllaDelHorizonte(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2027-09-07',
            weekdays: [1],
            times: [['18:00', null]]
        );

        $created = $this->generator()->generate($offer, $this->now());

        $ultimo = end($created)->getStartsAt();
        $limite = (new \DateTimeImmutable(self::NOW))->modify(sprintf('+%d days', ShiftGenerator::HORIZON_DAYS));

        $this->assertLessThanOrEqual($limite, $ultimo);
        $this->assertGreaterThan(10, \count($created), 'Con horizonte de meses tienen que salir varias semanas.');
    }

    /**
     * NUNCA HACIA ATRÁS. Al ampliar la serie en noviembre no se pueden inventar
     * los viernes de septiembre a los que nadie pudo apuntarse.
     */
    public function testNoSeGeneranTurnosEnElPasado(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-06-01',
            until: '2026-09-20',
            weekdays: [1],
            times: [['18:00', null]]
        );

        $created = $this->generator()->generate($offer, $this->now());

        foreach ($created as $shift) {
            $this->assertGreaterThanOrEqual(
                new \DateTimeImmutable('2026-09-07 00:00'),
                $shift->getStartsAt(),
                'Ningún turno puede caer antes de hoy.'
            );
        }
    }

    /**
     * IDEMPOTENTE: volver a generar no duplica nada. Es lo que permite que el
     * cron pase todos los días y que la receta se pueda editar sin miedo.
     */
    public function testVolverAGenerarNoDuplicaNada(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6],
            times: [['09:00', '11:00']]
        );

        $generator = $this->generator();

        $primera = $generator->generate($offer, $this->now());
        $segunda = $generator->generate($offer, $this->now());

        $this->assertCount(2, $primera);
        $this->assertSame([], $segunda, 'La segunda pasada no crea nada.');
        $this->assertCount(2, $offer->getShifts());
    }

    /**
     * Y NO RESUCITA LOS ANULADOS. Es el gesto del festivo: la fila se queda
     * anulada, y la pasada siguiente no la vuelve a crear porque ya existe.
     */
    public function testNoResucitaUnTurnoAnulado(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6],
            times: [['09:00', '11:00']]
        );

        $generator = $this->generator();
        $created = $generator->generate($offer, $this->now());
        $created[0]->cancel('festivo');

        $this->assertSame([], $generator->generate($offer, $this->now()));
        $this->assertTrue($offer->getShifts()->first()->isCancelled());
    }

    /**
     * El sync retira los turnos futuros que la receta ya no dicta: quien cambia
     * "sábados" por "domingos" espera que los sábados dejen de estar, no que
     * convivan los dos.
     */
    public function testElSyncRetiraLoQueLaRecetaYaNoDicta(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6],
            times: [['09:00', '11:00']]
        );

        $generator = $this->generator();
        $generator->generate($offer, $this->now());

        // Ahora se hace los domingos.
        $offer->setRepeatWeekdays([7]);

        $sync = $generator->sync($offer, $this->now());

        $this->assertSame(2, $sync['removed'], 'Los dos sábados se retiran.');
        $this->assertCount(2, $sync['created'], 'Y se abren los dos domingos.');
        $this->assertSame([], $sync['kept']);
    }

    /**
     * PERO NUNCA UNO CON GENTE APUNTADA. Se devuelve en `kept` para que la
     * pantalla lo diga: hay alguien contando con ese día, y borrarlo en silencio
     * dejaría a esa persona apuntada a algo que ya no existe.
     */
    public function testElSyncNoRetiraUnTurnoConGenteApuntada(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6],
            times: [['09:00', '11:00']]
        );

        $generator = $this->generator();
        $created = $generator->generate($offer, $this->now());
        $created[0]->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        $offer->setRepeatWeekdays([7]);

        $sync = $generator->sync($offer, $this->now());

        $this->assertSame(1, $sync['removed'], 'El sábado vacío sí se va.');
        $this->assertCount(1, $sync['kept'], 'El sábado con gente se queda y se avisa.');
        $this->assertSame($created[0], $sync['kept'][0]);
    }

    /**
     * NI UNO QUE HAYA TOCADO UNA PERSONA. Quien movió el turno de este viernes a
     * las siete porque había asamblea no espera que se borre al corregir una
     * errata en el título de la tarea, y ese borrado no daría ni error ni aviso.
     */
    public function testElSyncNoRetiraUnTurnoTocadoAMano(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-20',
            weekdays: [6],
            times: [['09:00', '11:00']]
        );

        $aMano = (new VolunteerShift())
            ->setStartsAt(new \DateTime('2026-09-16 19:00'))
            ->setManual(true);
        $offer->addShift($aMano);

        $sync = $this->generator()->sync($offer, $this->now());

        $this->assertSame(0, $sync['removed']);
        $this->assertContains($aMano, $offer->getShifts()->toArray());
    }

    /**
     * LAS FECHAS DE REPARTO SE PREGUNTAN AL CALENDARIO. El reparto no cae cada
     * siete días: cae los días que ese punto reparte, con sus cierres y
     * traslados ya aplicados.
     */
    public function testLaCadenciaDeRepartoPreguntaAlCalendarioDelNodo(): void
    {
        $node = new Node();

        $offer = $this->offer(
            VolunteerOffer::REPEAT_DELIVERY,
            from: '2026-09-07',
            until: '2026-09-30',
            times: [['17:00', '19:00']]
        );
        $offer->setNode($node);

        // Dos ciclos, y el calendario resuelve uno a un día que NO es múltiplo de
        // siete desde el otro: es justo lo que una cadencia fija no sabría hacer.
        $baskets = $this->createMock(BasketRepository::class);
        $baskets->method('findBetweenDates')->willReturn([new Basket(), new Basket()]);

        $calendar = $this->createMock(NodeDeliveryDate::class);
        $calendar->method('physicalDateFor')->willReturnOnConsecutiveCalls(
            new \DateTimeImmutable('2026-09-11'),
            new \DateTimeImmutable('2026-09-23'),
        );

        $created = $this->generator($baskets, $calendar)->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-11 17:00', '2026-09-23 17:00'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $created)
        );
    }

    /**
     * EL DESFASE CORRE LA FECHA QUE DICTA EL CALENDARIO. Las cestas se montan
     * antes de repartirlas —en Torremocha, la tarde anterior—, así que convocar
     * el día físico de la entrega llega tarde.
     */
    public function testElDesfaseAdelantaElTrabajoALaVisperaDelReparto(): void
    {
        $offer = $this->deliveryOffer(from: '2026-09-07', until: '2026-09-30');
        $offer->setRepeatOffsetDays(-1);

        $created = $this->generator(
            $this->basketsReturning(2),
            $this->calendarReturning('2026-09-11', '2026-09-23'),
        )->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-10 17:00', '2026-09-22 17:00'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $created)
        );
    }

    /**
     * El desfase se aplica ANTES de filtrar por la ventana, y ese orden no es
     * cosmético: el montaje de la víspera de un reparto del día 1 cae el último
     * día del mes anterior. Filtrando primero, un reparto justo después del
     * final de la ventana perdería su montaje, que sí caía dentro.
     */
    public function testElDesfaseSeAplicaAntesDeFiltrarPorLaVentana(): void
    {
        // La receta acaba el 22; el reparto es el 23, o sea fuera. Pero su
        // montaje es el 22, o sea dentro, y tiene que salir.
        $offer = $this->deliveryOffer(from: '2026-09-07', until: '2026-09-22');
        $offer->setRepeatOffsetDays(-1);

        $created = $this->generator(
            $this->basketsReturning(1),
            $this->calendarReturning('2026-09-23'),
        )->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-22 17:00'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $created)
        );
    }

    /**
     * Sin desfase, que es el valor por defecto, el trabajo cae el día del
     * reparto y todo lo anterior sigue igual.
     */
    public function testSinDesfaseElTrabajoCaeElDiaDelReparto(): void
    {
        $offer = $this->deliveryOffer(from: '2026-09-07', until: '2026-09-30');

        $created = $this->generator(
            $this->basketsReturning(1),
            $this->calendarReturning('2026-09-11'),
        )->generate($offer, $this->now());

        $this->assertSame(
            ['2026-09-11 17:00'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $created)
        );
    }

    /**
     * Y sin punto de recogida esa cadencia no da nada: no hay calendario al que
     * preguntar. El formulario lo caza con un error de validación; aquí se
     * comprueba que el generador no se inventa fechas.
     */
    public function testLaCadenciaDeRepartoSinNodoNoDaNada(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_DELIVERY,
            from: '2026-09-07',
            until: '2026-09-30',
            times: [['17:00', null]]
        );

        $this->assertSame([], $this->generator()->generate($offer, $this->now()));
    }

    /**
     * Un tramo que cruza la medianoche acaba al día siguiente. Sin esto, "cerrar
     * el invernadero de 23:00 a 00:30" tendría el fin antes del principio y la
     * duración saldría negativa.
     */
    public function testUnTramoQueCruzaMedianocheAcabaAlDiaSiguiente(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_ONCE,
            from: '2026-09-12',
            times: [['23:00', '00:30']]
        );

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertSame('2026-09-12 23:00', $created[0]->getStartsAt()->format('Y-m-d H:i'));
        $this->assertSame('2026-09-13 00:30', $created[0]->getEndsAt()->format('Y-m-d H:i'));
    }

    /**
     * Sin fecha de arranque no hay nada que generar, y no revienta: una tarea a
     * medio rellenar es un estado normal mientras alguien la escribe.
     */
    public function testSinFechaDeArranqueNoGeneraNada(): void
    {
        $offer = (new VolunteerOffer())->setRepeatType(VolunteerOffer::REPEAT_WEEKLY);

        $this->assertSame([], $this->generator()->generate($offer, $this->now()));
        $this->assertSame([], $this->generator()->moments($offer, $this->now()));
    }

    /**
     * Una receta semanal sin días marcados no da fechas. El formulario lo exige,
     * pero por código se puede llegar aquí y el silencio es mejor que inventarse
     * un día.
     */
    public function testUnaSemanalSinDiasMarcadosNoDaNada(): void
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_WEEKLY,
            from: '2026-09-07',
            until: '2026-09-30',
            times: [['10:00', null]]
        );

        $this->assertSame([], $this->generator()->generate($offer, $this->now()));
    }

    /**
     * Sin tramos horarios se abre uno a las nueve: es mejor un turno a una hora
     * discutible que ninguno, que dejaría la tarea publicada y sin nada a lo que
     * apuntarse.
     */
    public function testSinTramosAbreUnoALasNueve(): void
    {
        $offer = $this->offer(VolunteerOffer::REPEAT_ONCE, from: '2026-09-12');

        $created = $this->generator()->generate($offer, $this->now());

        $this->assertCount(1, $created);
        $this->assertSame('09:00', $created[0]->getStartsAt()->format('H:i'));
        $this->assertNull($created[0]->getEndsAt());
    }

    /**
     * Una tarea con receta, lista para generar.
     *
     * @param string                                      $type     una de las constantes REPEAT_*
     * @param string                                      $from     desde cuándo, "Y-m-d"
     * @param string|null                                 $until    hasta cuándo, "Y-m-d"
     * @param list<int>                                   $weekdays días de la semana ISO
     * @param list<array{0: string, 1: string|null}>|null $times    tramos horarios
     * @param int                                         $every    cada cuántas semanas
     */
    private function offer(
        string $type,
        string $from,
        ?string $until = null,
        array $weekdays = [],
        ?array $times = null,
        int $every = 1,
    ): VolunteerOffer {
        $offer = (new VolunteerOffer())
            ->setTitle('Trabajo de prueba')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setRepeatType($type)
            ->setRepeatFrom(new \DateTime($from))
            ->setRepeatEvery($every);

        if (null !== $until) {
            $offer->setRepeatUntil(new \DateTime($until));
        }

        if ([] !== $weekdays) {
            $offer->setRepeatWeekdays($weekdays);
        }

        if (null !== $times) {
            $offer->setRepeatTimes($times);
        }

        return $offer;
    }

    /**
     * El generador con sus tres dependencias dobladas.
     *
     * @param BasketRepository|null $baskets  los ciclos de reparto
     * @param NodeDeliveryDate|null $calendar quién resuelve la fecha física
     */
    /**
     * Una tarea con cadencia de reparto, con punto y con tramo de tarde.
     *
     * @param string $from  desde cuándo, 'Y-m-d'
     * @param string $until hasta cuándo, 'Y-m-d'
     */
    private function deliveryOffer(string $from, string $until): VolunteerOffer
    {
        $offer = $this->offer(
            VolunteerOffer::REPEAT_DELIVERY,
            from: $from,
            until: $until,
            times: [['17:00', '19:00']]
        );
        $offer->setNode(new Node());

        return $offer;
    }

    /**
     * Un repositorio de ciclos que devuelve tantos como se le pidan. Su contenido
     * da igual: quien resuelve la fecha de cada uno es el calendario.
     *
     * @param int $howMany cuántos ciclos devolver
     */
    private function basketsReturning(int $howMany): BasketRepository
    {
        $baskets = $this->createMock(BasketRepository::class);
        $baskets->method('findBetweenDates')->willReturn(array_fill(0, $howMany, new Basket()));

        return $baskets;
    }

    /**
     * Un calendario de reparto que resuelve las fechas físicas que se le digan,
     * en orden.
     *
     * @param string ...$dates las fechas 'Y-m-d' que devuelve, una por llamada
     */
    private function calendarReturning(string ...$dates): NodeDeliveryDate
    {
        $calendar = $this->createMock(NodeDeliveryDate::class);
        $calendar->method('physicalDateFor')->willReturnOnConsecutiveCalls(
            ...array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), $dates)
        );

        return $calendar;
    }

    private function generator(
        ?BasketRepository $baskets = null,
        ?NodeDeliveryDate $calendar = null,
    ): ShiftGenerator {
        return new ShiftGenerator(
            $this->createMock(EntityManagerInterface::class),
            $baskets ?? $this->createMock(BasketRepository::class),
            $calendar ?? $this->createMock(NodeDeliveryDate::class),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
