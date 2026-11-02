<?php

namespace App\EventListener;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Pone la huella del fichero a toda factura recibida que entra, venga por la web, el
 * correo o el bot: los tres caminos guardan el fichero y crean la factura, y aquí se
 * cubren los tres sin repetirlo en cada uno. Con ella la revisión avisa del mismo
 * documento subido dos veces.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class ReceivedInvoiceFingerprintListener
{
    public function __construct(private readonly InvoiceFileStore $files)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $invoice = $args->getObject();
        if (!$invoice instanceof ReceivedInvoice || $invoice->getFileHash() !== null) {
            return;
        }
        $path = $this->files->pathTo($invoice->getFileName());
        $hash = $path !== null ? hash_file('sha256', $path) : false;
        if ($hash !== false) {
            $invoice->fingerprint($hash);
        }
    }
}
