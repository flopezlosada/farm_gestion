<?php

namespace App\Service\Delivery;

/**
 * Línea de entrega de un socio en un día concreto, desacoplada de la
 * persistencia. Es la unidad que consume {@see NodeDeliverySheet} para dar
 * forma al listado en papel: lleva todo lo que el shaper necesita (nombre de
 * reparto, modalidad, cantidades, grupo, ciudad y vínculo de pareja) SIN
 * depender de la entidad {@see \App\Entity\WeeklyBasket} ni de su id.
 *
 * Tiene dos alimentadores: el camino MATERIALIZADO ({@see NodeDeliverySheet::build},
 * que mapea WeeklyBasket persistidos) y el camino de PROYECCIÓN (semanas futuras
 * dibujadas al vuelo desde las reglas, sin persistir). El mismo shaper produce el
 * mismo listado venga la línea de piedra o de dibujo.
 */
final class DeliveryLine
{
    /**
     * @param string      $nameForDelivery  Nombre tal como sale en el listado.
     * @param int|null    $basketShareId    Modalidad (BasketShare id); null = sin modalidad (legacy).
     * @param bool        $subscribedToEggs Si el hogar está suscrito a huevos (sufijo "H" del código).
     * @param float       $cestas           Cestas físicas (verdura) ya ponderadas (½ en compartidas, 0 en solo-huevo).
     * @param float       $dozens           Docenas de huevos.
     * @param int|null    $groupId          WeeklyBasketGroup id (agrupación del listado); null = sin grupo.
     * @param string|null $groupName        Nombre del grupo de recogida.
     * @param string|null $groupColor       Color del grupo (celda).
     * @param string|null $city             Ciudad del socio (solo se usa en grupos combinados "/").
     * @param int|null    $partnerId        Partner id (identidad para emparejar compartidas).
     * @param int|null    $sharePartnerId   Partner id de la pareja de cesta compartida, o null.
     * @param string|null $relocatedFromLabel Si el socio recoge esta semana en un nodo
     *        distinto al suyo (override de nodo), la etiqueta de su origen de CASA
     *        ("Nodo · Grupo", deduplicada cuando coinciden), para marcarlo "de X" en la
     *        sección Trasladados del nodo destino. null = recoge en su nodo (normal).
     */
    public function __construct(
        public readonly string $nameForDelivery,
        public readonly ?int $basketShareId,
        public readonly bool $subscribedToEggs,
        public readonly float $cestas,
        public readonly float $dozens,
        public readonly ?int $groupId,
        public readonly ?string $groupName,
        public readonly ?string $groupColor,
        public readonly ?string $city,
        public readonly ?int $partnerId,
        public readonly ?int $sharePartnerId,
        public readonly ?string $relocatedFromLabel = null,
    ) {
    }
}
