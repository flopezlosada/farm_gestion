<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use App\Repository\ConsumerGroupOrderRepository;

/**
 * Los pedidos del grupo de consumo de una socia, ordenados como los lee ella: por
 * ronda (para saber en cuáles ya se ha apuntado), lo que tiene por recoger y lo que
 * ya se llevó.
 *
 * Lo usan la pantalla del grupo de consumo y la portada del panel; vivía dentro de la
 * primera y la portada lo habría repetido.
 */
class PartnerConsumerGroupOrders
{
    public function __construct(private readonly ConsumerGroupOrderRepository $orders)
    {
    }

    /**
     * Sus pedidos en una sola consulta, repartidos.
     *
     * Por recoger y ya llevado sólo cuentan los confirmados y con algo pedido: lo no
     * confirmado aún puede caerse por no llegar al mínimo, así que no es una entrega.
     * Se parte en dos porque juntos, bajo «se te entregará con la cesta», iban los
     * pedidos de hace meses, ya entregados y cobrados, anunciando una entrega que no
     * va a pasar. Sin fecha de entrega cuenta como por recoger: la comisión aún no la
     * ha puesto. Una ronda cancelada no es ni lo uno ni lo otro.
     *
     * @param Partner                 $partner la socia
     * @param \DateTimeInterface|null $today   hoy (por defecto, el de verdad)
     *
     * @return array{byRound: array<int, ConsumerGroupOrder>, upcoming: list<ConsumerGroupOrder>, past: list<ConsumerGroupOrder>}
     */
    public function forPartner(Partner $partner, ?\DateTimeInterface $today = null): array
    {
        $today = \DateTimeImmutable::createFromInterface($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $byRound = [];
        $upcoming = [];
        $past = [];

        foreach ($this->orders->findByPartner($partner) as $order) {
            $round = $order->getRound();
            $byRound[$round->getId()] = $order;
            if (!$round->isConfirmed() || $order->isEmpty() || $round->getStatus() === ConsumerGroupRound::STATUS_CANCELLED) {
                continue;
            }

            $delivery = $round->getDeliveryDate();
            $isPast = $round->getStatus() === ConsumerGroupRound::STATUS_DELIVERED
                || ($delivery !== null && $delivery < $today);
            $isPast ? $past[] = $order : $upcoming[] = $order;
        }

        // Llegan de la entrega más lejana a la más antigua: lo que hay por recoger se
        // lee al revés, lo más cercano primero.
        return ['byRound' => $byRound, 'upcoming' => array_reverse($upcoming), 'past' => $past];
    }
}
