<?php

namespace App\Entity;

/**
 * Qué pasa con la cesta de quien deja de trabajar en la asociación. Mientras trabaja
 * la cesta es gratis sin preguntar; al irse, alguien tiene que decidir: o la paga
 * como cualquier socix, o se le sigue regalando (todo el tiempo o hasta una fecha).
 * Sin decidir no hay valor: la cuota avisa y no se cobra hasta que se decida.
 */
enum WorkerLeaveBasket: string
{
    /** Tras la baja paga su cesta como cualquier socix. */
    case Paid = 'paid';

    /** Tras la baja se le sigue regalando, sin fin o hasta {@see Worker::getBasketGiftUntil()}. */
    case Gift = 'gift';

    /**
     * Para pantallas y formularios.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'La paga',
            self::Gift => 'Se le regala',
        };
    }
}
