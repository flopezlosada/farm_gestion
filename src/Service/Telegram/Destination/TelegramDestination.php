<?php

namespace App\Service\Telegram\Destination;

use App\Entity\User;
use App\Service\Telegram\TelegramInput;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un apartado al que puede ir lo que llega al bot: facturas, y lo que venga
 * (cosecha, huevos, fichajes…).
 *
 * AÑADIR UNA OPCIÓN AL BOT ES ESCRIBIR UNA CLASE QUE IMPLEMENTE ESTO. Nada más: el
 * tag la registra, {@see \App\Service\Telegram\TelegramBot} la tiene en cuenta y,
 * si compite con otras por el mismo mensaje, {@see \App\Service\Telegram\TelegramClassifier}
 * elige entre ellas leyendo su {@see description()}.
 *
 * Cada destino comprueba sus permisos en {@see accepts()}: estar vinculado al bot no
 * da más derechos que los que esa persona tiene en la web.
 *
 * Cada apartado GUARDA ({@see commit()}) y sabe DESHACER lo que guardó
 * ({@see undo()}). El bot guarda sin preguntar cuando lo tiene claro y le cuenta a
 * quien lo mandó lo que ha entendido, con un botón «Es otra cosa»: si se equivocó,
 * se deshace aquí y se manda al apartado bueno. Por eso el resumen de
 * {@see TelegramOutcome} tiene que decir lo entendido, no sólo «recibido».
 */
#[AutoconfigureTag('app.telegram_destination')]
interface TelegramDestination
{
    /** Identificador estable y corto (`invoice`, `harvest`…). Se guarda en cada envío. */
    public function key(): string;

    /** Nombre para un botón y para la web: «Factura recibida», «Cosecha». */
    public function label(): string;

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
     * Se queda con el envío.
     *
     * @param TelegramInput $input Lo recibido. Su fichero, si lo pide, es una copia
     *                             que puede llevarse (moverla).
     * @param User          $user  Quién lo mandó.
     *
     * @throws TelegramRejected Si no puede quedárselo; el mensaje es para quien lo mandó.
     */
    public function commit(TelegramInput $input, User $user): TelegramOutcome;

    /**
     * Deshace lo que guardó {@see commit()}, porque no era para este apartado.
     *
     * @param string $ref Lo que devolvió commit().
     *
     * @return string|null Null si se ha deshecho (o ya no estaba); si no se puede,
     *                     por qué, para quien lo pidió: «Esa factura ya está anotada
     *                     en el libro; corrígela desde la web».
     */
    public function undo(string $ref): ?string;

    /**
     * Una línea para la ayuda: qué se le puede mandar. Null si esta persona no
     * puede usarlo ahora (sección apagada, sin permiso), para no prometer nada.
     *
     * @param User $user Quién pregunta.
     */
    public function help(User $user): ?string;
}
