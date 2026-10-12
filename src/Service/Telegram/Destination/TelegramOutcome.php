<?php

namespace App\Service\Telegram\Destination;

/**
 * Lo que ha hecho un apartado con un envío: qué ha creado (para poder deshacerlo)
 * y cómo se lo cuenta a quien lo mandó.
 */
final class TelegramOutcome
{
    /**
     * @param string $ref     Lo creado, en términos del apartado (el id de la factura…).
     * @param string $summary Lo que ha entendido y guardado, en una frase: «Factura de
     *                        Ferretería Torrelaguna, 23,40 €, del 05/08/2026». Es lo que
     *                        permite ver de un vistazo si el bot se ha equivocado.
     * @param string|null $doubt Si el apartado lo ha guardado pero duda de que sea para
     *                        él (una foto que no parece una factura), la pregunta para
     *                        quien lo mandó. Entonces el bot no dice «hecho»: pregunta
     *                        si se guarda igualmente o se descarta.
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $summary,
        public readonly ?string $doubt = null,
    ) {
    }
}
