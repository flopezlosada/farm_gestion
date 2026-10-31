<?php

namespace App\Service\Accounting\Invoice;

use App\Service\Accounting\OfficialNotice\OfficialNoticeIntake;

/**
 * Cada correo del buzón a su sitio: el que dice venir de un organismo oficial se
 * trata como aviso de notificación (y nunca como factura, sea auténtico o no); todos
 * los demás, a la bandeja de facturas como siempre.
 *
 * Es lo que se le pasa a {@see InvoiceMailbox::drain()}: un correo oficial deja 0
 * facturas y acaba en la carpeta «Revisar» del buzón, que es donde una persona mira
 * lo que la app no ha tratado como factura.
 */
class MailboxSorter
{
    /**
     * @param OfficialNoticeIntake $notices  Los avisos de notificación oficial.
     * @param MailedInvoiceIntake  $invoices Las facturas.
     */
    public function __construct(
        private readonly OfficialNoticeIntake $notices,
        private readonly MailedInvoiceIntake $invoices,
    ) {
    }

    /**
     * Entrega el correo a quien le toca.
     *
     * @param string $rawMessage Correo completo (cabeceras y cuerpo), tal cual llega.
     *
     * @return int Facturas que ha dejado en la bandeja; 0 si era un aviso oficial.
     */
    public function take(string $rawMessage): int
    {
        return $this->notices->take($rawMessage)->isOfficial() ? 0 : $this->invoices->take($rawMessage);
    }
}
