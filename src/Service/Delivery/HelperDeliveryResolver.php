<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
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
        $lines = [];
        foreach ($this->deliveringHelpers($node, $basket) as $entry) {
            $lines[] = $this->lineFromHelper($entry['helper'], $entry['withEggs']);
        }

        return $lines;
    }

    /**
     * Voluntarios que este nodo+semana recogen HUEVOS y cuántas docenas: los que
     * reparten, tienen docenas en su config y no se las han retirado ya.
     *
     * Lo usa la retirada de huevos de un reparto entero
     * ({@see NodeEggRescheduler}) para incluir al albergue en el lote: si la
     * granja no tiene huevos esa semana, tampoco los tiene para los voluntarios.
     *
     * @param Node   $node
     * @param Basket $basket
     * @return array<int, array{helper: Helper, dozens: float}> Indexado por helper.id.
     */
    public function helpersWithEggs(Node $node, Basket $basket): array
    {
        $found = [];
        foreach ($this->deliveringHelpers($node, $basket) as $id => $entry) {
            $dozens = $entry['helper']->getBasketEggDozens();
            if ($entry['withEggs'] && $dozens > 0.0) {
                $found[$id] = ['helper' => $entry['helper'], 'dozens' => $dozens];
            }
        }

        return $found;
    }

    /**
     * Voluntarios que efectivamente reparten en este nodo+semana, con la marca de
     * si llevan huevos. Núcleo compartido por las dos vistas de arriba (líneas
     * para el listado, docenas para el lote) para no duplicar la resolución de
     * estancias y saltos.
     *
     * @param Node   $node
     * @param Basket $basket
     * @return array<int, array{helper: Helper, withEggs: bool}> Indexado por helper.id.
     */
    private function deliveringHelpers(Node $node, Basket $basket): array
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

        $skipped = array_flip($this->skipRepository->helperIdsSkippingDate(array_keys($helpers), $physicalDate));
        // Retirada sólo de huevos (la granja no los tiene esa semana): el
        // voluntario sigue recogiendo su verdura, con la línea de huevos a cero.
        $withoutEggs = array_flip($this->skipRepository->helperIdsSkippingComponent(
            array_keys($helpers),
            $physicalDate,
            BasketComponent::ID_EGGS,
        ));

        $result = [];
        foreach ($helpers as $id => $helper) {
            if (isset($skipped[$id])) {
                continue;
            }
            $result[$id] = ['helper' => $helper, 'withEggs' => !isset($withoutEggs[$id])];
        }

        return $result;
    }

    /**
     * Mapea la config de cesta de un voluntario a una línea de entrega. Sin
     * modalidad (basketShareId null) ni grupo de socios: la sección "Albergue"
     * se construye en {@see NodeDeliverySheet::shape}.
     *
     * `subscribedToEggs` se mantiene según su CONFIG aunque esta semana no se
     * los lleve: el voluntario sigue siendo de los que llevan huevos, es esta
     * entrega la que va sin ellos.
     *
     * @param Helper $helper
     * @param bool   $withEggs False si esta semana se le han retirado los huevos.
     * @return DeliveryLine
     */
    private function lineFromHelper(Helper $helper, bool $withEggs = true): DeliveryLine
    {
        $configured = $helper->getBasketEggDozens();
        $dozens = $withEggs ? $configured : 0.0;

        return new DeliveryLine(
            nameForDelivery: $helper->getName() ?? '',
            basketShareId: null,
            subscribedToEggs: $configured > 0.0,
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
