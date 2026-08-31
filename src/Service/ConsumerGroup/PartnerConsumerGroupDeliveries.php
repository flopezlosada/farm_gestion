<?php

namespace App\Service\ConsumerGroup;

use App\Entity\Partner;
use App\Repository\ConsumerGroupOrderRepository;
use App\Service\Delivery\PartnerMonthProjection;

/**
 * Entregas del grupo de consumo que una socia va a recibir, para pintarlas en su
 * calendario (tanto el panel del socio como el calendario del gestor). Solo apuntes
 * de pedidos CONFIRMADOS y PAGADOS con entrega futura (si no ha pagado, no aparece).
 *
 * El pedido es una entrega FIJA e INDEPENDIENTE de la cesta: la fecha la pone la
 * comisión y el socio no la mueve, y llega ese día haya cesta o no. Por eso se
 * devuelven dos banderas que resuelve el calendario (no una sola):
 *  - `withBasket`: ese día además le toca cesta (no saltada). Solo cambia el TEXTO
 *    ("junto a tu cesta" vs "en tu punto de recogida"), no si se muestra.
 *  - `hasSlot`: ese día es una celda SELECCIONABLE del calendario (hay entrega
 *    proyectada, aunque esté saltada). Si la hay, el pedido se ve en el panel de ese
 *    día; si NO (semana que el socio no recoge), no hay dónde seleccionarlo y se
 *    muestra en el aviso de arriba. Así no se duplica.
 *
 * Ambas se resuelven contra la PROYECCIÓN del calendario, no contra las filas
 * `weekly_basket` materializadas: las semanas futuras se DIBUJAN del patrón (no se
 * materializan hasta entrar en operación), así que consultar la tabla daría siempre
 * falso para una entrega futura. La proyección es la misma fuente que pinta el panel.
 */
class PartnerConsumerGroupDeliveries
{
    public function __construct(
        private readonly ConsumerGroupOrderRepository $orders,
        private readonly PartnerMonthProjection $projection,
    ) {
    }

    /**
     * @return array<array{order: \App\Entity\ConsumerGroupOrder, withBasket: bool, hasSlot: bool}>
     */
    public function upcomingForPartner(Partner $partner): array
    {
        $out = [];
        $byMonth = []; // 'Y-m' => array{withBasket: array<'Y-m-d', true>, anySlot: array<'Y-m-d', true>}
        foreach ($this->orders->findDeliverableUpcomingForPartner($partner, new \DateTime('today')) as $order) {
            if ($order->isEmpty()) {
                continue;
            }
            $date = $order->getRound()?->getDeliveryDate();
            $withBasket = false;
            $hasSlot = false;
            if ($date !== null) {
                $key = $date->format('Y-m');
                $byMonth[$key] ??= $this->slotDatesFor($partner, (int) $date->format('Y'), (int) $date->format('n'));
                $ymd = $date->format('Y-m-d');
                $withBasket = isset($byMonth[$key]['withBasket'][$ymd]);
                $hasSlot = isset($byMonth[$key]['anySlot'][$ymd]);
            }
            $out[] = ['order' => $order, 'withBasket' => $withBasket, 'hasSlot' => $hasSlot];
        }

        return $out;
    }

    /**
     * Fechas físicas ('Y-m-d') del mes según la proyección del calendario, en dos
     * conjuntos: las que el socio recibe cesta (no saltada) y las que tienen entrega
     * proyectada de cualquier tipo (incluida la saltada, que sigue siendo un día
     * seleccionable en el calendario).
     *
     * @return array{withBasket: array<string, true>, anySlot: array<string, true>}
     */
    private function slotDatesFor(Partner $partner, int $year, int $month): array
    {
        $withBasket = [];
        $anySlot = [];
        foreach ($this->projection->projectMonth($partner, $year, $month) as $slot) {
            $slotDate = $slot['date'] ?? null;
            if (!$slotDate instanceof \DateTimeInterface) {
                continue;
            }
            $ymd = $slotDate->format('Y-m-d');
            $anySlot[$ymd] = true;
            if (empty($slot['skipped'])) {
                $withBasket[$ymd] = true;
            }
        }

        return ['withBasket' => $withBasket, 'anySlot' => $anySlot];
    }
}
