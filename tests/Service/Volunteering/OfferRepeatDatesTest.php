<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\VolunteerOffer;
use App\Repository\BasketRepository;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Volunteering\OfferRepeatDates;
use PHPUnit\Framework\TestCase;

/**
 * En qué fechas se copia una tarea que se repite.
 *
 * Unit test con dobles: lo que hace {@see NodeDeliveryDate} —qué días reparte
 * de verdad cada punto de recogida— ya lo cubre su propio test, así que aquí se
 * finge y se comprueba sólo lo que es de este servicio: el rango, el filtrado,
 * la hora que heredan las copias y la aritmética de las cadencias fijas.
 */
class OfferRepeatDatesTest extends TestCase
{
    /**
     * El caso normal: una tarea semanal hasta una fecha. La original NO se
     * repite a sí misma, y el último día cuenta entero.
     */
    public function testSemanalLlegaHastaLaFechaFinalSinRepetirLaOriginal(): void
    {
        // Viernes 4 de septiembre de 2026, a las 17:00.
        $offer = $this->makeOffer('2026-09-04 17:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_WEEKLY,
            new \DateTimeImmutable('2026-10-02')
        );

        $this->assertSame(
            ['2026-09-11 17:00', '2026-09-18 17:00', '2026-09-25 17:00', '2026-10-02 17:00'],
            $this->format($dates)
        );
    }

    /**
     * "Hasta el 2 de octubre" incluye el reparto del 2 de octubre aunque sea por
     * la tarde. Si la fecha final se tomara como las 00:00 de ese día —que es lo
     * que sale de un input de tipo date— se perdería siempre la última copia, y
     * nadie entendería por qué falta justo esa.
     */
    public function testElUltimoDiaCuentaEnteroAunqueLaTareaSeaPorLaTarde(): void
    {
        $offer = $this->makeOffer('2026-09-04 20:30');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_WEEKLY,
            new \DateTimeImmutable('2026-09-11')
        );

        $this->assertSame(['2026-09-11 20:30'], $this->format($dates));
    }

    /**
     * Una fecha final anterior a la tarea no es un error a gritos: son cero
     * copias. Quien llama decide qué decir.
     */
    public function testUnaFechaFinalAnteriorNoDaNingunaCopia(): void
    {
        $offer = $this->makeOffer('2026-09-04 17:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_WEEKLY,
            new \DateTimeImmutable('2026-08-01')
        );

        $this->assertSame([], $dates);
    }

    public function testQuincenalSaltaUnaSemana(): void
    {
        $offer = $this->makeOffer('2026-09-04 10:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_BIWEEKLY,
            new \DateTimeImmutable('2026-10-16')
        );

        $this->assertSame(
            ['2026-09-18 10:00', '2026-10-02 10:00', '2026-10-16 10:00'],
            $this->format($dates)
        );
    }

    /**
     * Mensual conserva el DÍA DE LA SEMANA y su posición en el mes.
     *
     * Esto existe por un fallo real: antes "mensual" eran 28 días fijos, así que
     * el segundo martes de septiembre se convertía en el 6 de octubre —primer
     * martes— y a los seis meses la serie ya no caía ni en la misma semana. Una
     * tarea de voluntariado se organiza por el día de la semana, porque de eso
     * depende quién puede venir.
     */
    public function testMensualConservaElDiaDeLaSemanaYNoSuma28Dias(): void
    {
        // Martes 8 de septiembre de 2026: el SEGUNDO martes del mes.
        $offer = $this->makeOffer('2026-09-08 18:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_MONTHLY,
            new \DateTimeImmutable('2026-12-31')
        );

        // Segundos martes de octubre, noviembre y diciembre. Con +28 días
        // habrían salido el 6-oct, el 3-nov y el 1-dic: todos primeros martes.
        $this->assertSame(
            ['2026-10-13 18:00', '2026-11-10 18:00', '2026-12-08 18:00'],
            $this->format($dates)
        );
    }

    /**
     * Y "el último viernes" sigue siendo el último aunque el mes tenga cinco.
     * Es la misma semántica que usa el calendario de reparto para los puntos
     * mensuales, así que las dos partes de la aplicación cuentan igual.
     */
    public function testMensualEnLaUltimaSemanaSeQuedaEnLaUltima(): void
    {
        // Viernes 25 de septiembre de 2026: último viernes del mes.
        $offer = $this->makeOffer('2026-09-25 09:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_MONTHLY,
            new \DateTimeImmutable('2026-11-30')
        );

        // Octubre de 2026 tiene cinco viernes; el último es el 30.
        $this->assertSame(['2026-10-30 09:00', '2026-11-27 09:00'], $this->format($dates));
    }

    /**
     * El caso que justifica todo esto: las fechas salen del calendario de
     * reparto, así que las semanas en las que ese punto no reparte —quincenal
     * fuera de fase, festivo, cierre— sencillamente no generan tarea.
     */
    public function testLasFechasDeRepartoSaltanLasSemanasEnQueElPuntoNoReparte(): void
    {
        $offer = $this->makeOffer('2026-09-04 17:00', new Node());

        $service = $this->makeService(deliveries: [
            '2026-09-04' => '2026-09-04',
            '2026-09-11' => null,          // cerrado: festivo
            '2026-09-18' => '2026-09-18',
            '2026-09-25' => '2026-09-24',  // trasladado al jueves
        ]);

        $dates = $service->compute(
            $offer,
            OfferRepeatDates::CADENCE_DELIVERY,
            new \DateTimeImmutable('2026-09-30')
        );

        // La del 4 es la tarea original, no se repite a sí misma. La hora es la
        // suya, no la medianoche con la que llegan las fechas de reparto.
        $this->assertSame(['2026-09-18 17:00', '2026-09-24 17:00'], $this->format($dates));
    }

    /**
     * Sin punto de recogida no hay calendario al que preguntar, así que esa
     * cadencia ni se ofrece ni se acepta. Devolver cero fechas en silencio
     * dejaría a quien la eligió sin saber por qué no ha pasado nada.
     */
    public function testSinPuntoDeRecogidaLaCadenciaDeRepartoNoExiste(): void
    {
        $offer = $this->makeOffer('2026-09-04 17:00');

        $this->assertNotContains(OfferRepeatDates::CADENCE_DELIVERY, $this->makeService()->cadencesFor($offer));

        $this->expectException(\InvalidArgumentException::class);
        $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_DELIVERY,
            new \DateTimeImmutable('2026-12-31')
        );
    }

    /**
     * El tope existe para el dedazo en el año de la fecha final: sin él, "hasta
     * 2126" crearía miles de tareas que habría que borrar una a una.
     */
    public function testUnaFechaFinalDisparatadaSeCortaEnElTope(): void
    {
        $offer = $this->makeOffer('2026-09-04 17:00');

        $dates = $this->makeService()->compute(
            $offer,
            OfferRepeatDates::CADENCE_WEEKLY,
            new \DateTimeImmutable('2036-09-04')
        );

        $this->assertCount(OfferRepeatDates::MAX, $dates);
    }

    /**
     * El servicio con las dependencias fingidas.
     *
     * @param array<string, string|null> $deliveries fecha del ciclo => fecha física de reparto, o null si no reparte
     */
    private function makeService(array $deliveries = []): OfferRepeatDates
    {
        $baskets = $this->createMock(BasketRepository::class);
        $baskets->method('findBetweenDates')->willReturn(
            array_map(
                static fn (string $isoDate): Basket => (new Basket())->setDate(new \DateTime($isoDate)),
                array_keys($deliveries)
            )
        );

        $deliveryDate = $this->createMock(NodeDeliveryDate::class);
        $deliveryDate->method('physicalDateFor')->willReturnCallback(
            static function (Basket $basket) use ($deliveries): ?\DateTimeImmutable {
                $physical = $deliveries[$basket->getDate()->format('Y-m-d')] ?? null;

                return null === $physical ? null : new \DateTimeImmutable($physical);
            }
        );

        return new OfferRepeatDates($baskets, $deliveryDate);
    }

    /**
     * @param string    $startsAt fecha y hora de la tarea
     * @param Node|null $node     punto de recogida donde ocurre, si ocurre en uno
     */
    private function makeOffer(string $startsAt, ?Node $node = null): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setStartsAt(new \DateTime($startsAt))
            ->setNode($node);
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string> las fechas legibles, para comparar de un vistazo
     */
    private function format(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $dates);
    }
}
