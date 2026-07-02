<?php

namespace App\Service\Delivery;

use App\Entity\Partner;

/**
 * Proyección mensual de las entregas de un socio, segregada de
 * {@see DeliveryCalendarProjector} —`final` por convención de la casa— para que
 * quien solo necesita LEER la composición proyectada dependa de una abstracción
 * testeable con un doble. Symfony auto-aliasa la interfaz a su único implementador.
 */
interface PartnerMonthProjection
{
    /**
     * Slots del mes (uno por semana) con su composición proyectada (piedra o dibujo).
     *
     * @return list<array{basket: \App\Entity\Basket, items: list<array{component: \App\Entity\BasketComponent, amount: string}>}>
     */
    public function projectMonth(Partner $partner, int $year, int $month): array;
}
