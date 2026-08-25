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
 * Para nodos mensuales (cadence=monthly, El Berrueco) el punto abre una sola
 * semana al mes, la que fija `Node.monthly_week` contada sobre las ocurrencias
 * de su día de reparto en el mes natural (1ª, 2ª, 3ª o la última). A diferencia
 * de la alternancia quincenal, esa posición no se desplaza con los cierres: el
 * 2º jueves es el 2º jueves de cada mes.
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
     * @throws \LogicException Si al nodo le falta el dato que define su cadencia.
     */
    public function physicalDateFor(Basket $basket, Node $node): ?\DateTimeImmutable
    {
        $physical = $this->naiveDate($basket, $node);

        if (!$this->deliversOperativelyOn($physical, $node)) {
            return null;
        }

        return $this->applyException($basket, $node, $physical);
    }

    /**
     * Fecha BASE para CONTAR posiciones mensuales: como {@see physicalDateFor()}
     * pero una CANCELACIÓN conserva la fecha en vez de anularla. Sirve a la
     * semántica "pegajosa" del orden mensual ({@see MonthlyOperativeOrderResolver}):
     * la semana cancelada sigue ocupando su posición en el mes (quien iba detrás
     * no se renumera); solo quien tenía ESA semana hace fallback. Los traslados
     * sí aplican (cambian fecha y, con ella, el mes que cuenta), y en nodos
     * biweekly la alineación sigue siendo operativa (un cierre global desplaza
     * la cadencia del nodo entero — esa semántica no cambia).
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @return \DateTimeImmutable|null Fecha base, o null si la cadencia no toca.
     * @throws \LogicException Si al nodo le falta el dato que define su cadencia.
     */
    public function baselineDateFor(Basket $basket, Node $node): ?\DateTimeImmutable
    {
        $physical = $this->naiveDate($basket, $node);

        if (!$this->deliversOperativelyOn($physical, $node)) {
            return null;
        }

        $exception = $this->exceptionRepository->findForBasketAndNode($basket, $node);
        if ($exception === null || $exception->isCancelled()) {
            return $physical;
        }

        $shifted = $exception->getShiftedDate();

        return $shifted instanceof \DateTimeImmutable
            ? $shifted
            : \DateTimeImmutable::createFromInterface($shifted);
    }

    /**
     * Fecha física teórica del nodo en este Basket según SU día de la semana
     * y su cadencia, SIN aplicar excepciones de calendario. Devuelve null si
     * el nodo es biweekly y esta semana no le toca repartir.
     *
     * A diferencia de {@see physicalDateFor()}, no consulta DeliveryException:
     * sirve para listar los repartos "normales" candidatos a recibir una
     * excepción (el picker del CRUD de excepciones), sin que un cierre o
     * traslado ya registrado oculte o desplace la fecha que el usuario espera
     * reconocer.
     *
     * @param Basket $basket Ciclo semanal global.
     * @param Node $node Nodo donde se entrega.
     * @return \DateTimeImmutable|null Fecha física teórica, o null si la
     *         cadencia del nodo no reparte esa semana.
     * @throws \LogicException Si al nodo le falta el dato que define su cadencia.
     */
    public function operativeDateFor(Basket $basket, Node $node): ?\DateTimeImmutable
    {
        $physical = $this->naiveDate($basket, $node);

        if (!$this->deliversTheoreticallyOn($physical, $node)) {
            return null;
        }

        return $physical;
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
     * ¿La cadencia del nodo hace que reparta en esta fecha física, con criterio
     * OPERATIVO? En los quincenales la alineación descuenta los cierres
     * globales, para que un festivo desplace la cadencia del nodo una semana
     * igual que hace con la cohorte A/B (cf {@see BiweeklyCohortResolver}).
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     * @throws \LogicException Si al nodo le falta el dato que define su cadencia.
     */
    private function deliversOperativelyOn(\DateTimeImmutable $physical, Node $node): bool
    {
        return match ($node->getCadence()) {
            Node::CADENCE_BIWEEKLY => $this->alignsOperatively($physical, $node),
            Node::CADENCE_MONTHLY  => $this->matchesMonthlyWeek($physical, $node),
            default                => true,
        };
    }

    /**
     * ¿La cadencia del nodo hace que reparta en esta fecha física, con criterio
     * TEÓRICO (semanas crudas, sin descontar cierres)? Lo usa
     * {@see operativeDateFor()} / el picker del CRUD de excepciones, que debe
     * ver las fechas tal cual, sin desplazamientos.
     *
     * En los mensuales coincide con el criterio operativo: la semana no es una
     * alternancia que se corra, es una posición del calendario natural — el 2º
     * jueves sigue siendo el 2º jueves aunque el mes pasado hubiera un cierre.
     * Si esa semana concreta cae en festivo, lo resuelve una DeliveryException
     * (cancelación o traslado), que se aplica después.
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     * @throws \LogicException Si al nodo le falta el dato que define su cadencia.
     */
    private function deliversTheoreticallyOn(\DateTimeImmutable $physical, Node $node): bool
    {
        return match ($node->getCadence()) {
            Node::CADENCE_BIWEEKLY => $this->basketAlignsWithAnchor($physical, $node),
            Node::CADENCE_MONTHLY  => $this->matchesMonthlyWeek($physical, $node),
            default                => true,
        };
    }

    /**
     * Para nodos monthly: ¿es esta fecha física la semana del mes en que el
     * punto abre? Se cuenta sobre las ocurrencias del día de reparto dentro del
     * mes natural, sin tocar BBDD: el 2º jueves es el 2º jueves con
     * independencia de en qué día caiga el 1 del mes.
     *
     * Que sea aritmética pura es deliberado: {@see MonthlyOperativeOrderResolver}
     * pregunta a este servicio qué semanas reparte el nodo, así que resolver
     * aquí la posición mensual consultando aquel volvería circular la llamada.
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     * @throws \LogicException Si el nodo es monthly sin monthly_week.
     */
    private function matchesMonthlyWeek(\DateTimeImmutable $physical, Node $node): bool
    {
        $week = $node->getMonthlyWeek();
        if ($week === null) {
            throw new \LogicException(sprintf(
                'Node "%s" es monthly pero no tiene monthly_week.',
                $node->getName() ?? '(sin nombre)'
            ));
        }

        if ($week === Node::MONTHLY_WEEK_LAST) {
            // Última ocurrencia de este día de la semana: siete días después ya
            // estamos en otro mes.
            return $physical->modify('+7 days')->format('m') !== $physical->format('m');
        }

        // Días 1-7 → 1ª ocurrencia del día, 8-14 → 2ª, 15-21 → 3ª…
        return intdiv((int) $physical->format('j') - 1, 7) + 1 === $week;
    }

    /**
     * Para nodos biweekly: ¿alinea esta fecha física con el ancla del nodo
     * según el calendario TEÓRICO (semanas crudas, sin descontar cierres)?
     * La usa {@see operativeDateFor()} / el picker del CRUD de excepciones,
     * que debe ver las fechas tal cual, sin desplazamientos.
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     */
    private function basketAlignsWithAnchor(\DateTimeImmutable $physical, Node $node): bool
    {
        return $this->weeksFromAnchor($physical, $node) % 2 === 0;
    }

    /**
     * Para nodos biweekly: ¿alinea esta fecha física con el ancla DESCONTANDO
     * los cierres globales intermedios? Un cierre (festivo/puente: sin
     * trabajadores no hay verdura, no reparte nadie ni este nodo) desplaza la
     * cadencia del nodo una semana, igual que la cohorte A/B
     * (cf {@see BiweeklyCohortResolver}). La usa {@see physicalDateFor()}.
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return bool
     */
    private function alignsOperatively(\DateTimeImmutable $physical, Node $node): bool
    {
        $anchor = $this->anchorOf($node);

        [$after, $before] = $physical >= $anchor ? [$anchor, $physical] : [$physical, $anchor];
        $closures = $this->exceptionRepository->countGlobalCancellationsBetween($after, $before);

        // Normaliza el módulo: $closures no debería superar las semanas crudas,
        // pero si lo hiciera el operador % de PHP daría negativo.
        return (((($this->weeksFromAnchor($physical, $node) - $closures) % 2) + 2) % 2) === 0;
    }

    /**
     * Número absoluto de semanas entre el ancla del nodo y una fecha física.
     *
     * @param \DateTimeImmutable $physical
     * @param Node $node
     * @return int
     */
    private function weeksFromAnchor(\DateTimeImmutable $physical, Node $node): int
    {
        $secondsDiff = $physical->getTimestamp() - $this->anchorOf($node)->getTimestamp();

        return abs((int) round($secondsDiff / self::SECONDS_PER_WEEK));
    }

    /**
     * Ancla del nodo biweekly como inmutable.
     *
     * @param Node $node
     * @return \DateTimeImmutable
     * @throws \LogicException Si el nodo es biweekly sin anchor_date.
     */
    private function anchorOf(Node $node): \DateTimeImmutable
    {
        $anchor = $node->getAnchorDate();
        if ($anchor === null) {
            throw new \LogicException(sprintf(
                'Node "%s" es biweekly pero no tiene anchor_date.',
                $node->getName() ?? '(sin nombre)'
            ));
        }

        return $anchor instanceof \DateTimeImmutable
            ? $anchor
            : \DateTimeImmutable::createFromInterface($anchor);
    }
}
