<?php

namespace App\Service\Accounting\Invoice;

use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El buzón de correo al que los proveedores mandan sus facturas.
 *
 * Vacía la bandeja de entrada: cada correo se entrega a quien sepa sacar las
 * facturas y después se archiva, sin borrarlo, para que el original quede siempre
 * en el buzón. Va a «Archivadas» si dejó alguna factura y a «Revisar» si no (un
 * correo sin adjunto, o con un enlace para descargar la factura): esos los mira
 * una persona, porque la app no sabe qué hacer con ellos.
 *
 * Un correo que falla al entregarse se queda en la bandeja de entrada y se vuelve
 * a intentar al día siguiente, sin parar a los demás.
 */
class InvoiceMailbox
{
    /** Carpeta de los correos de los que salió alguna factura. */
    public const FOLDER_FILED = 'Archivadas';

    /** Carpeta de los correos sin nada que valga como factura. */
    public const FOLDER_REVIEW = 'Revisar';

    /** Correos que se traen del servidor de cada vez: acota la memoria. */
    private const CHUNK = 5;

    /**
     * @param string          $host     Servidor IMAP (vacío = buzón apagado).
     * @param string          $username Usuario del buzón, la dirección completa.
     * @param string          $password Contraseña del buzón.
     * @param LoggerInterface $logger   Rastro de los correos que fallan.
     */
    public function __construct(
        #[Autowire('%env(INVOICE_MAILBOX_HOST)%')]
        private readonly string $host,
        #[Autowire('%env(INVOICE_MAILBOX_USER)%')]
        private readonly string $username,
        #[Autowire('%env(INVOICE_MAILBOX_PASSWORD)%')]
        private readonly string $password,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Si hay buzón al que entrar. */
    public function isConfigured(): bool
    {
        return trim($this->host) !== '' && trim($this->username) !== '' && $this->password !== '';
    }

    /**
     * Correos que esperan en la bandeja de entrada, sin tocarlos.
     *
     * @return int Número de correos.
     */
    public function pending(): int
    {
        $mailbox = $this->open();
        try {
            return $mailbox->inbox()->messages()->count();
        } finally {
            $mailbox->disconnect();
        }
    }

    /**
     * Entrega cada correo de la bandeja de entrada y lo archiva según lo que dejó.
     *
     * @param callable(string): int $take Recibe el correo en crudo y devuelve cuántas facturas dejó.
     *
     * @return array{messages: int, invoices: int, review: int, failed: int} Recuento de la pasada.
     */
    public function drain(callable $take): array
    {
        $done = ['messages' => 0, 'invoices' => 0, 'review' => 0, 'failed' => 0];
        $mailbox = $this->open();
        try {
            foreach ([self::FOLDER_FILED, self::FOLDER_REVIEW] as $folder) {
                $mailbox->folders()->firstOrCreate($folder);
            }
            $mailbox->inbox()->messages()->withHeaders()->withBody()->each(
                function (Message $message) use ($take, &$done): void {
                    try {
                        $invoices = $take((string) $message);
                        $message->move($invoices > 0 ? self::FOLDER_FILED : self::FOLDER_REVIEW, true);
                    } catch (\Throwable $e) {
                        $this->logger->error('Buzón de facturas: no se pudo entregar un correo', ['uid' => $message->uid(), 'error' => $e->getMessage()]);
                        ++$done['failed'];

                        return;
                    }
                    ++$done['messages'];
                    $done['invoices'] += $invoices;
                    $done['review'] += $invoices > 0 ? 0 : 1;
                },
                self::CHUNK,
            );
        } finally {
            $mailbox->disconnect();
        }

        return $done;
    }

    /**
     * Conexión al buzón. Por SSL/TLS desde el primer byte (puerto 993), validando
     * el certificado: la contraseña no viaja nunca en claro.
     *
     * @return Mailbox Buzón conectado al primer uso.
     */
    private function open(): Mailbox
    {
        return new Mailbox([
            'host' => $this->host,
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }
}
