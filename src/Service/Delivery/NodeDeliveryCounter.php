<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;

/**
 * Cuenta el reparto por nodo en un Basket (viernes-ciclo), con la MISMA
 * decisión piedra-vs-dibujo POR NODO que el listado de reparto (vía
 * {@see DeliveryModeResolver}): cada nodo cuenta de su piedra si la tiene
 * materializada, o de su proyección al vuelo si le toca dibujar. Los nodos en
 * EMPTY (no reparten ese día, cancelados, o pasados sin piedra) NO aparecen en
 * el resultado: el llamante los interpreta como "sin reparto".
 *
 * Lo usan tanto las pestañas del listado v2 ({@see \App\Controller\DeliveryController})
 * como el dashboard por roles ({@see \App\Controller\DefaultController}), para que
 * ambos enseñen lo mismo: una semana en curso aún no generada se DIBUJA (no sale
 * como "0" ni "pendiente"), igual que en la tabla de reparto.
 */
class NodeDeliveryCounter
{
    public function __construct(
        private readonly DeliveryModeResolver $deliveryModeResolver,
        private readonly WeeklyBasketGenerator $generator,
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly WeeklyBasketItemRepository $weeklyBasketItemRepo,
    ) {
    }

    /**
     * Conteo por nodo para un Basket: entregas, grupos de recogida, cestas
     * físicas de verdura y docenas de huevos. Las cestas/docenas salen de la
     * composición (materializada o proyectada): la ponderación ½ de las
     * compartidas y el 0 de "solo huevos" ya están estampados en cada ítem, así
     * que aquí sólo se suma.
     *
     * @param Node[] $nodes  Nodos a contar (normalmente todos).
     * @param Basket $basket Semana (Basket-ciclo).
     * @return array<int, array{wbg:int, socios:int, cestas:float, docenas:float}> Indexado
     *         por nodeId. Los nodos sin reparto (modo EMPTY) se omiten.
     */
    public function countsByNode(array $nodes, Basket $basket): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $mode = $this->deliveryModeResolver->mode($node, $basket);
            if ($mode === DeliveryModeResolver::STONE) {
                $counts[$node->getId()] = $this->countStone($node, $basket);
            } elseif ($mode === DeliveryModeResolver::DRAW) {
                $counts[$node->getId()] = $this->countDraw($node, $basket);
            }
        }

        return $counts;
    }

    /**
     * Conteo desde la piedra: las cestas/docenas salen de los WeeklyBasketItem
     * materializados.
     *
     * @return array{wbg:int, socios:int, cestas:float, docenas:float}
     */
    private function countStone(Node $node, Basket $basket): array
    {
        $deliveries = $this->weeklyBasketRepo->findForNodeAndBasket($node, $basket);
        $amountsByWbId = $this->weeklyBasketItemRepo->componentAmountsFor($deliveries);

        $cestas = 0.0;
        $docenas = 0.0;
        $groupIds = [];
        foreach ($deliveries as $wb) {
            $amounts = $amountsByWbId[$wb->getId()] ?? [];
            $cestas += (float) ($amounts[BasketComponent::ID_VEGETABLES] ?? 0);
            $docenas += (float) ($amounts[BasketComponent::ID_EGGS] ?? 0);
            $wbg = $wb->getWeeklyBasketGroup();
            if ($wbg !== null) {
                $groupIds[$wbg->getId()] = true;
            }
        }

        return ['socios' => count($deliveries), 'wbg' => count($groupIds), 'cestas' => $cestas, 'docenas' => $docenas];
    }

    /**
     * Conteo desde el dibujo: las cestas/docenas salen de la composición
     * proyectada al vuelo (cada entrega trae sus items con componente y cantidad).
     *
     * @return array{wbg:int, socios:int, cestas:float, docenas:float}
     */
    private function countDraw(Node $node, Basket $basket): array
    {
        $deliveries = $this->generator->projectDeliveriesForNode($node, $basket);

        $cestas = 0.0;
        $docenas = 0.0;
        $groupIds = [];
        foreach ($deliveries as $delivery) {
            foreach ($delivery['items'] as $item) {
                $componentId = $item['component']->getId();
                if ($componentId === BasketComponent::ID_VEGETABLES) {
                    $cestas += (float) $item['amount'];
                } elseif ($componentId === BasketComponent::ID_EGGS) {
                    $docenas += (float) $item['amount'];
                }
            }
            $wbg = $delivery['weeklyBasket']->getWeeklyBasketGroup();
            if ($wbg !== null) {
                $groupIds[$wbg->getId()] = true;
            }
        }

        return ['socios' => count($deliveries), 'wbg' => count($groupIds), 'cestas' => $cestas, 'docenas' => $docenas];
    }
}
