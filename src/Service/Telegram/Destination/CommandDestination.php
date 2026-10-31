<?php

namespace App\Service\Telegram\Destination;

/**
 * Un apartado que se usa como una orden con formato fijo («caja huerta 400,56»).
 *
 * Su {@see TelegramDestination::accepts()} ya reconoce el mensaje por cómo está
 * escrito, así que si lo acepta es suyo: {@see \App\Service\Telegram\TelegramBot} no lo
 * pasa por el clasificador (no hace falta IA para algo que se sabe seguro) y, si el
 * apartado lo rechaza, dice por qué sin ofrecer otros apartados.
 *
 * Su accepts() tiene que ser estricto: si acepta algo que no es suyo, se lo queda sin
 * preguntar a nadie.
 */
interface CommandDestination extends TelegramDestination
{
}
