<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\ConsumerGroupOrder;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\User;
use App\Service\Accounting\MonthNames;

/**
 * El borrador de la remesa del grupo de consumo de un mes: un recibo por socia con la
 * suma de sus pedidos, a mes vencido. Cada pedido queda enlazado a su recibo, que es lo
 * que al enviar la remesa dice cuáles están cobrados.
 *
 * Qué pedidos entran lo decide quien llama
 * ({@see \App\Repository\ConsumerGroupOrderRepository::findToChargeByReceiptIn()}): aquí
 * sólo se agrupan, para poder probarlo sin base de datos.
 */
class RemittanceFromConsumerGroup
{
    /**
     * El borrador del mes con esos pedidos, agrupados por socia.
     *
     * @param \DateTimeInterface       $month  cualquier día del mes que se cobra
     * @param list<ConsumerGroupOrder> $orders los pedidos que se pueden cobrar ese mes
     * @param User|null                $by     quien la prepara
     */
    public function build(\DateTimeInterface $month, array $orders, ?User $by): Remittance
    {
        $period = Remittance::firstOfMonth($month);
        $label = sprintf('Grupo de consumo %s %s', mb_strtoupper(MonthNames::long((int) $period->format('n'))), $period->format('Y'));
        $remittance = new Remittance(Remittance::KIND_CONSUMER_GROUP, $period, $label, Remittance::SOURCE_APP, $by);

        foreach ($this->byPartner($orders) as $partnerOrders) {
            $cents = array_sum(array_map(static fn (ConsumerGroupOrder $o): int => (int) round($o->getTotal() * 100), $partnerOrders));
            if ($cents <= 0) {
                continue;
            }
            $payer = $partnerOrders[0]->getPartner();
            $line = RemittanceLine::forPayer($payer, number_format($cents / 100, 2, '.', ''), sprintf('GC %s %s', $period->format('Ym'), $payer->getMandateReference()));
            $remittance->addLine($line);
            foreach ($partnerOrders as $order) {
                $order->setRemittanceLine($line);
            }
        }

        return $remittance;
    }

    /**
     * Los pedidos agrupados por socia, en el orden en que llegan.
     *
     * @param list<ConsumerGroupOrder> $orders
     *
     * @return array<int, non-empty-list<ConsumerGroupOrder>>
     */
    private function byPartner(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $groups[spl_object_id($order->getPartner())][] = $order;
        }

        return $groups;
    }
}
