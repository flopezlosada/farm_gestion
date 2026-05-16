<?php

namespace App\Entity;

use ADesigns\CalendarBundle\Entity\EventEntity as CalendarEventEntity;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

/**
 * Adapter para mostrar una cesta semanal (Basket) como evento en el calendario.
 * Al pinchar en el evento se va a la pantalla de reparto detallado del viernes.
 *
 * Marrón terracota para diferenciarlo del resto de eventos de granja.
 */
class BasketDeliveryEntity extends CalendarEventEntity
{
    protected $bgColor = '#A8553A';

    public function __construct(Router $router, int $id, string $title, \DateTime $startDatetime)
    {
        parent::__construct($title, $startDatetime, null, true);
        $this->setId($id);
        $this->url = $router->generate('delivery_show', ['basketId' => $id]);
    }
}
