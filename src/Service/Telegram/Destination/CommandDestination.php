<?php

namespace App\Service\Telegram\Destination;

use App\Service\Telegram\TelegramInput;

/**
 * Un apartado que se usa como una orden con formato fijo («caja huerta 400,56»).
 *
 * Si {@see recognizes()} reconoce el mensaje por cómo está escrito, es suyo:
 * {@see \App\Service\Telegram\TelegramBot} no lo pasa por el clasificador (no hace falta
 * IA para algo que se sabe seguro) y, si el apartado lo rechaza, dice por qué sin ofrecer
 * otros apartados. Lo demás que acepte (una nota de voz) se clasifica como lo de
 * cualquier otro apartado.
 *
 * recognizes() tiene que ser estricto: si reconoce algo que no es suyo, se lo queda sin
 * preguntar a nadie.
 */
interface CommandDestination extends TelegramDestination
{
    /**
     * Si el mensaje está escrito como la orden de este apartado.
     *
     * @param TelegramInput $input Lo recibido, que este apartado ya acepta.
     */
    public function recognizes(TelegramInput $input): bool;
}
