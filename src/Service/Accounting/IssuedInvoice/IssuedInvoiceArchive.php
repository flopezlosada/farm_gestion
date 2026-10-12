<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\IssuedInvoice;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * El PDF de cada factura emitida, guardado al emitirla.
 *
 * Se guarda en vez de regenerarse porque una factura emitida no puede cambiar: si
 * después cambian el número de cuenta, la dirección de la asociación o la propia
 * plantilla, lo que recibió el cliente tiene que seguir siendo lo que hay aquí.
 *
 * Fuera del docroot: sale sólo por el controlador, con el permiso de contabilidad.
 */
class IssuedInvoiceArchive
{
    public function __construct(
        #[Autowire('%app.issued_invoice_files_dir%')]
        private readonly string $directory,
        private readonly Filesystem $filesystem,
    ) {
    }

    /** Guarda el PDF de una factura emitida, sustituyendo uno anterior si lo hubiera. */
    public function store(IssuedInvoice $invoice, string $pdf): void
    {
        $this->filesystem->dumpFile($this->fileFor($invoice), $pdf);
    }

    /** Dónde está su PDF, o null si no está (una base restaurada sin sus ficheros). */
    public function pathTo(IssuedInvoice $invoice): ?string
    {
        $path = $this->fileFor($invoice);

        return is_file($path) ? $path : null;
    }

    private function fileFor(IssuedInvoice $invoice): string
    {
        if ($invoice->getId() === null) {
            throw new \LogicException('Una factura sin guardar no tiene PDF.');
        }

        return sprintf('%s/%d.pdf', rtrim($this->directory, '/'), $invoice->getId());
    }
}
