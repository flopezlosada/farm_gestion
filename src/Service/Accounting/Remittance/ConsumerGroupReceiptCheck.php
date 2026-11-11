<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\ConsumerGroupOrder;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;

/**
 * Antes de enviar la remesa del grupo de consumo: que cada recibo siga cobrando
 * exactamente lo que deben sus pedidos.
 *
 * Entre el borrador y el envío un pedido puede cambiar: se paga en metálico, se
 * corrige la cantidad, se cancela la ronda. El recibo guarda el importe del día del
 * borrador, así que enviarlo tal cual cobraría de más (o dos veces). La salida es tirar
 * el borrador y prepararlo otra vez, que recalcula con lo de hoy.
 *
 * Un recibo sin pedidos enlazados es uno añadido a mano: no se comprueba.
 */
class ConsumerGroupReceiptCheck
{
    /**
     * Los recibos que ya no cuadran con sus pedidos, en palabras.
     *
     * @param Remittance               $remittance la remesa del grupo de consumo
     * @param list<ConsumerGroupOrder> $orders     los pedidos enlazados a sus recibos
     *
     * @return list<string>
     */
    public function problems(Remittance $remittance, array $orders): array
    {
        $problems = [];
        foreach ($this->byLine($orders) as $lineOrders) {
            /** @var RemittanceLine $line */
            $line = $lineOrders[0]->getRemittanceLine();
            if ($line->getRemittance() !== $remittance) {
                continue;
            }
            $due = array_sum(array_map(static fn (ConsumerGroupOrder $o): int => $o->isChargeableByReceiptNow() ? (int) round($o->getTotal() * 100) : 0, $lineOrders));
            $charged = (int) round((float) $line->getAmount() * 100);
            if ($due !== $charged) {
                $problems[] = sprintf(
                    '%s: el recibo cobra %s € y sus pedidos deben ahora %s € (alguno se ha pagado, cambiado o cancelado después del borrador). Tira el borrador y prepáralo otra vez.',
                    $line->getDebtorName(),
                    number_format($charged / 100, 2, ',', '.'),
                    number_format($due / 100, 2, ',', '.'),
                );
            }
        }

        return $problems;
    }

    /**
     * Los pedidos agrupados por su recibo.
     *
     * @param list<ConsumerGroupOrder> $orders
     *
     * @return array<int, non-empty-list<ConsumerGroupOrder>>
     */
    private function byLine(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            if ($order->getRemittanceLine() !== null) {
                $groups[spl_object_id($order->getRemittanceLine())][] = $order;
            }
        }

        return $groups;
    }
}
