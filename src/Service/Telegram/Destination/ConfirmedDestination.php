<?php

namespace App\Service\Telegram\Destination;

use App\Entity\User;
use App\Service\Telegram\TelegramInput;

/**
 * Un apartado que NO guarda sin que quien lo mandó confirme lo entendido (un arqueo de
 * caja: un importe mal oído se quedaría en el libro como un descuadre).
 *
 * El bot, en vez de guardarlo y ofrecer «Es otra cosa», le enseña lo entendido
 * ({@see preview()}) con «Sí, está bien» / «No, está mal». Sólo con el «sí» llama a
 * {@see TelegramDestination::commit()}, que recibe en {@see TelegramInput::$transcript}
 * lo que se enseñó de una nota de voz, para guardar eso y no otra lectura del audio.
 *
 * Desde la web no se pregunta: quien lo coloca allí ya lo está viendo.
 */
interface ConfirmedDestination extends TelegramDestination
{
    /**
     * Lo entendido, sin guardar nada.
     *
     * @param TelegramInput $input Lo recibido.
     * @param User          $user  Quién lo mandó.
     *
     * @throws TelegramRejected Si no se puede guardar; el mensaje es para quien lo mandó.
     */
    public function preview(TelegramInput $input, User $user): TelegramPreview;
}
