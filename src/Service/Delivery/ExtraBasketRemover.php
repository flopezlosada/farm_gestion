<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;

/**
 * Deshacer (QUITAR) las cestas/huevos extra de un socio en una semana, segregado de
 * {@see ExtraBasketEditor} —`final` por convención de la casa— para que quien solo
 * necesita esa acción dependa de una abstracción testeable con un doble. Hermana de
 * {@see ExtraBasketAdder}. Symfony auto-aliasa la interfaz a su único implementador.
 */
interface ExtraBasketRemover
{
    /**
     * Quita TODOS los overrides de cesta extra del socio en esa semana y revierte la
     * piedra si estaba generada.
     *
     * Si entre esos extras había cestas TRASLADADAS SUMANDO desde otras semanas, sus
     * intents se ajustan según por qué se quita el añadido — es lo que evita perder o
     * duplicar una cesta:
     *  - `$movedTo` null (el día se VACÍA: "no recoge", quitar la extra): esas cestas
     *    vuelven a estar PENDIENTES, recuperables desde la papelera de su semana de origen.
     *  - `$movedTo` con valor (el añadido VIAJA con la cesta a otro día): siguen colocadas,
     *    ahora en esa otra semana.
     *
     * @param Partner     $partner Socio.
     * @param Basket      $basket  Semana/ciclo de reparto.
     * @param string|null $actor   Quién lo origina (ver PartnerEvent::$actor).
     * @param Basket|null $movedTo Semana a la que viaja el añadido, o null si simplemente se quita.
     * @return bool true si había algún extra que quitar; false si no había nada.
     */
    public function removeExtra(Partner $partner, Basket $basket, ?string $actor = null, ?Basket $movedTo = null): bool;
}
