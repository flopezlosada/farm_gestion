<?php

namespace App\Service\Telegram\Destination;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Service\Telegram\TelegramChat;
use App\Service\Telegram\TelegramInput;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una cosa que el bot sabe hacer con lo que le mandan: guardar una factura,
 * apuntar una cosecha, guardar fotos para una galería…
 *
 * AÑADIR UNA OPCIÓN AL BOT ES ESCRIBIR UNA CLASE QUE IMPLEMENTE ESTO. Nada más: el
 * tag la registra, {@see \App\Service\Telegram\TelegramBot} la tiene en cuenta y,
 * si compite con otras por el mismo mensaje, {@see \App\Service\Telegram\TelegramClassifier}
 * elige entre ellas leyendo su {@see description()}.
 *
 * Cada destino comprueba sus permisos en {@see accepts()}: estar vinculado al bot no
 * da más derechos que los que esa persona tiene en la web.
 */
#[AutoconfigureTag('app.telegram_destination')]
interface TelegramDestination
{
    /** Identificador estable y corto (`invoice`, `harvest`…). */
    public function key(): string;

    /**
     * Qué es lo que recibe, para que el clasificador sepa distinguirlo de lo de los
     * demás destinos. Una o dos frases concretas: «Factura o ticket de compra
     * emitido por un proveedor, con importe», no «documentos».
     */
    public function description(): string;

    /**
     * Si podría ser suyo y esta persona puede usarlo: por la forma del mensaje
     * (foto, voz…), por si su sección está encendida y por los permisos. Sin mirar el
     * contenido: eso lo hace el clasificador cuando hay más de un candidato.
     *
     * @param TelegramInput $input Lo recibido.
     * @param User          $user  Quién lo manda.
     */
    public function accepts(TelegramInput $input, User $user): bool;

    /**
     * Lo atiende y contesta por `$chat`.
     *
     * @param TelegramInput $input Lo recibido.
     * @param TelegramLink  $link  Quién lo manda.
     * @param TelegramChat  $chat  Para contestar.
     */
    public function handle(TelegramInput $input, TelegramLink $link, TelegramChat $chat): void;

    /**
     * Una línea para la ayuda: qué se le puede mandar. Null si esta persona no
     * puede usarlo ahora (sección apagada, sin permiso), para no prometer nada.
     *
     * @param User $user Quién pregunta.
     */
    public function help(User $user): ?string;
}
