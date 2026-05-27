<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;

/**
 * Resuelve la fecha física de reparto para un Basket dado en un Node concreto.
 *
 * Un `Basket` representa el ciclo semanal global (un viernes). Un `Node`
 * (Torremocha, Cascorro, Midori) entrega físicamente esa cesta en SU día
 * de la semana, que puede coincidir con el viernes-ciclo (Torremocha) o
 * caer en otro día (Madrid: miércoles).
 *
 * Para nodos quincenales (cadence=biweekly), no todos los Baskets son
 * operativos: alternan semanas que reparten vs. semanas vacías, anclados
 * en `Node.anchor_date`.
 *
 * Es el punto único de verdad de "¿qué día reparte realmente el nodo X en
 * el ciclo Y?". Además del calendario teórico (día + cadencia), aplica las
 * excepciones de calendario (DeliveryException): un festivo o cierre puede
 * cancelar el reparto de un nodo en un ciclo, o trasladarlo a otro día. Así
 * todos los consumidores (generador, DeadlineRule) heredan ese override sin
 * duplicar la consulta.
 *
 * Introducido en sub-fase 8.8b (2026-05-26). Excepciones añadidas en 8.8d
 * (2026-05-27).
 */
class NodeDeliveryDate
{
    private const SECONDS_PER_WEEK = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly DeliveryExceptionRepository $exceptionRepository,
    ) {
    }

    /**
     * Devuelve la fecha física de reparto del Node en este Basket, o null
     * si el Node no reparte (quincenal fuera de fase, o excepción que
     * cancela el reparto). Si una excepción traslada el reparto, devuelve
     * la fecha trasladada.
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @return \DateTimeImmutable|null Fecha del día físico de reparto, o null si no reparte.
     * @throws \LogicException Si el nodo es biweekly sin anchor_date.
     */
    public function physicalDateFor(Basket $basket, Node $node): ?\DateTimeImmutable
    {
        $physical = $this->naiveDate($basket, $node);

        if ($node->getCadence() === Node::CADENCE_BIWEEKLY) {
            if (!$this->basketAlignsWithAnchor($physical, $node)) {
                return null;
            }
        }

        return $this->applyException($basket, $node, $physical);
    }

    /**
     * Aplica la excepción de calendario que corresponda a (basket, node),
     * dando prioridad a la específica del nodo sobre la global.
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @param \DateTimeImmutable $physical Fecha física teórica (sin excepción).
     * @return \DateTimeImmutable|null La fecha trasladada si la excepción
     *         mueve el reparto; null si lo cancela; la fecha teórica si no
     *         hay excepción.
     */
    private function applyException(Basket $basket, Node $node, \DateTimeImmutable $physical): ?\DateTimeImmutable
    {
        $exception = $this->exceptionRepository->findForBasketAndNode($basket, $node);
        if ($exception === null) {
            return $physical;
        }

        if ($exception->isCancelled()) {
            return null;
        }

        $shifted = $exception->getShiftedDate();

        return $shifted instanceof \DateTimeImmutable
            ? $shifted
            : \DateTimeImmutable::createFromInterface($shifted);
    }

    /**
     * Conveniencia: ¿este Node reparte en este Basket?
     *
     * @param Basket $basket
     * @param Node $node
     * @return bool
     */
    public function deliversInBasket(Basket $basket, Node $node): bool
    {
        return $this->physicalDateFor($basket, $node) !== null;
    }

    /**
     * Día de la semana del nodo para este Basket, SIN tener en cuenta la
     * cadencia ni las excepciones: devuelve siempre la fecha (el miércoles,
     * viernes…) aunque el nodo sea quincenal y esa semana no reparta. Útil
     * para pintar la fecha del nodo en una mini-timeline y atenuar aparte las
     * semanas vacías.
     *
     * @param Basket $basket
     * @param Node $node
     * @return \DateTimeImmutable
     */
    public function weekdayDateFor(Basket $basket, Node $node): \DateTimeImmutable
    {
        return $this->naiveDate($basket, $node);
    }

    /**
     * Fecha física "naif" — sin tener en cuenta la cadencia. Se calcula
     * desplazando el día del Basket hasta el delivery_weekday del nodo.
     *
     * @param Basket $basket
     * @param Node $node
     * @return \DateTimeImmutable
     */
    private function naiveDate(Basket $basket, Node $node): \DateTimeImmutable
    {
        $basketDate = $basket->getDate();
        $immutable = $basketDate instanceof \DateTimeImmutable
            ? $basketDate
            : \DateTimeImmutable::createFromInterface($basketDate);

        $basketWeekday = (int) $immutable->format('N');
        $nodeWeekday = $node->getDeliveryWeekday();
        $diffDays = $nodeWeekday - $basketWeekday;

        if ($diffDays === 0) {
            return $immutable;
        }

        return $immutable->modify(sprintf('%+d days', $diffDays));
    }

    /**
     * Para nodos biweekly: ¿alinea esta fecha física con el ancla del nodo?
     * (= mismas semanas pares respecto al ancla).
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     */
    private function basketAlignsWithAnchor(\DateTimeImmutable $physical, Node $node): bool
    {
        $anchor = $node->getAnchorDate();
        if ($anchor === null) {
            throw new \LogicException(sprintf(
                'Node "%s" es biweekly pero no tiene anchor_date.',
                $node->getName() ?? '(sin nombre)'
            ));
        }

        $anchorImmutable = $anchor instanceof \DateTimeImmutable
            ? $anchor
            : \DateTimeImmutable::createFromInterface($anchor);

        $secondsDiff = $physical->getTimestamp() - $anchorImmutable->getTimestamp();
        $weeksDiff = (int) round($secondsDiff / self::SECONDS_PER_WEEK);

        return abs($weeksDiff) % 2 === 0;
    }
}
