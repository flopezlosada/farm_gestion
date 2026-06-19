<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Helper;
use App\Entity\Node;
use App\Repository\HelperBasketSkipRepository;
use App\Repository\StayRepository;

/**
 * Única fuente de verdad de qué voluntarios del albergue ({@see Helper}) reparten
 * en un nodo+semana. La cesta del voluntario es DERIVADA: no se materializa en
 * weekly_basket ni pasa por {@see WeeklyBasketGenerator}; se dibuja al vuelo a
 * partir de la config del voluntario (activa/nodo/cantidades) + las fechas de su
 * estancia confirmada, menos los "no recoge" ({@see \App\Entity\HelperBasketSkip}).
 *
 * Devuelve {@see DeliveryLine} con isHelper=true para que tanto la hoja en papel
 * ({@see NodeDeliverySheet}) como la pantalla v2 las pinten en una sección propia
 * "Albergue", y los totales del nodo las sumen. Mantener al voluntario fuera de la
 * maquinaria de socios (PBS, shifts, cohortes) es deliberado: más simple y sin
 * riesgo de romper el reparto de socios.
 */
class HelperDeliveryResolver
{
    /**
     * Nodo por defecto de la cesta del voluntario cuando {@see Helper::$basketNode}
     * es null. Hoy todos los voluntarios recogen en Torremocha; dejar el campo
     * nullable + este default evita tener que asignar nodo a cada registro.
     */
    public const DEFAULT_NODE_NAME = 'Torremocha';

    public function __construct(
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly StayRepository $stayRepository,
        private readonly HelperBasketSkipRepository $skipRepository,
    ) {
    }

    /**
     * Líneas de entrega de los voluntarios que recogen en este nodo en este
     * Basket. Vacío si el nodo no reparte esa semana (quincenal fuera de fase o
     * excepción que cancela), si ninguno tiene estancia confirmada que cubra el
     * día, o si todos los que la cubren han marcado "no recoge".
     *
     * @param Node   $node
     * @param Basket $basket
     * @return DeliveryLine[]
     */
    public function forNodeAndBasket(Node $node, Basket $basket): array
    {
        $physicalDate = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
        if ($physicalDate === null) {
            return [];
        }

        $includeNullNode = $node->getName() === self::DEFAULT_NODE_NAME;
        $stays = $this->stayRepository->findConfirmedDeliveringOn($physicalDate, $node, $includeNullNode);

        // Un voluntario aparece una sola vez aunque (defensivamente) tuviera dos
        // estancias confirmadas solapando el mismo día.
        $helpers = [];
        foreach ($stays as $stay) {
            $helper = $stay->getHelper();
            $helpers[$helper->getId()] = $helper;
        }
        if ($helpers === []) {
            return [];
        }

        $skipped = $this->skipRepository->helperIdsSkippingDate(array_keys($helpers), $physicalDate);
        $skipped = array_flip($skipped);

        $lines = [];
        foreach ($helpers as $id => $helper) {
            if (isset($skipped[$id])) {
                continue;
            }
            $lines[] = $this->lineFromHelper($helper);
        }

        return $lines;
    }

    /**
     * Mapea la config de cesta de un voluntario a una línea de entrega. Sin
     * modalidad (basketShareId null) ni grupo de socios: la sección "Albergue"
     * se construye en {@see NodeDeliverySheet::shape}.
     *
     * @param Helper $helper
     * @return DeliveryLine
     */
    private function lineFromHelper(Helper $helper): DeliveryLine
    {
        $dozens = $helper->getBasketEggDozens();

        return new DeliveryLine(
            nameForDelivery: $helper->getName() ?? '',
            basketShareId: null,
            subscribedToEggs: $dozens > 0.0,
            cestas: (float) $helper->getBasketVegBaskets(),
            dozens: $dozens,
            groupId: null,
            groupName: null,
            groupColor: null,
            city: null,
            partnerId: null,
            sharePartnerId: null,
            relocatedFromLabel: null,
            isHelper: true,
        );
    }
}
