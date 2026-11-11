<?php

namespace App\Service\Partner;

use App\Repository\RemittanceRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Desde cuándo vale un cambio de cesta: el día 1 del mes siguiente, o el del primer mes
 * cuyas cuotas aún no se han cobrado si ése es posterior.
 *
 * La cuota es mensual y se paga la que corresponde a la cesta, se recoja o no: un cambio a
 * mitad de mes dejaría a alguien recibiendo una cesta y pagando otra. Nunca vale desde el
 * mes en curso, aunque su remesa no se haya mandado: sus repartos ya empezaron, y cambiar
 * desde el día 1 rehace semanas ya repartidas. La fecha la fija el servidor; quien cambia
 * la cesta no la elige.
 */
final class BasketChangeDate
{
    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * El día en que empieza la cesta nueva si el cambio se aplica hoy.
     *
     * @return \DateTimeImmutable El día 1 de un mes, a las 00:00.
     */
    public function next(): \DateTimeImmutable
    {
        $nextMonth = $this->clock->now()->modify('first day of next month')->setTime(0, 0);
        $uncharged = $this->remittances->firstUnchargedBasketMonth();

        return $uncharged !== null && $uncharged > $nextMonth ? $uncharged : $nextMonth;
    }
}
