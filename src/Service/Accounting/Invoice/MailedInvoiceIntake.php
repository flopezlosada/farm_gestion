<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\FileMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Un correo del buzón de facturas → sus adjuntos en la bandeja de facturas recibidas.
 *
 * Cada adjunto que pase las reglas de la subida web es una factura en cola, igual
 * que si la hubieran subido a mano: la lee el reintento de la lectura y la confirma
 * quien lleva la contabilidad. Lo que no sea factura (unas condiciones, un folleto)
 * lo marca la lectura como «otro» y se descarta en la bandeja.
 *
 * No sabe nada de IMAP: recibe el correo en crudo, así que se prueba con correos
 * de verdad guardados en un fichero.
 */
class MailedInvoiceIntake
{
    /**
     * @param InvoiceFileStore       $files     Archivo de facturas.
     * @param ValidatorInterface     $validator Para aplicar las reglas de la subida web.
     * @param EntityManagerInterface $em        Para guardar.
     */
    public function __construct(
        private readonly InvoiceFileStore $files,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Deja en la bandeja los adjuntos del correo que valgan como factura.
     *
     * @param string $rawMessage Correo completo (cabeceras y cuerpo), tal cual llega.
     *
     * @return int Facturas que ha dejado; 0 si el correo no traía ninguna.
     */
    public function take(string $rawMessage): int
    {
        $invoices = array_filter(array_map(
            fn (Attachment $attachment): ?ReceivedInvoice => $this->store($attachment),
            array_filter(self::attachments($rawMessage), self::isDocument(...)),
        ));
        if ($invoices !== []) {
            $this->em->flush();
        }

        return \count($invoices);
    }

    /**
     * Adjuntos del correo, abriendo los correos reenviados «como adjunto». Lo normal
     * es que la factura llegue al buzón de la asociación y alguien la reenvíe aquí;
     * reenviada así, el correo original viaja como un .eml con nombre, que la
     * librería sólo abre cuando NO lo lleva.
     *
     * @param string $rawMessage Correo completo.
     *
     * @return list<Attachment> Adjuntos, con los de los correos reenviados en su lugar.
     */
    private static function attachments(string $rawMessage): array
    {
        return array_merge(...array_map(
            fn (Attachment $attachment): array => strtolower($attachment->contentType()) === 'message/rfc822'
                ? self::attachments($attachment->contents())
                : [$attachment],
            (new FileMessage($rawMessage))->attachments(),
        ));
    }

    /**
     * Si el adjunto es un documento y no la decoración del correo. El logotipo de
     * la firma llega como imagen con Content-ID, porque el HTML lo pinta por esa
     * referencia; sin descartarlo, cada correo traería una «factura» de más.
     *
     * @param Attachment $attachment Adjunto del correo.
     *
     * @return bool true si se guarda como factura.
     */
    private static function isDocument(Attachment $attachment): bool
    {
        return !(str_starts_with(strtolower($attachment->contentType()), 'image/') && $attachment->contentId() !== null);
    }

    /**
     * Guarda un adjunto como factura en cola, si pasa las reglas de formato y tamaño.
     *
     * @param Attachment $attachment Adjunto del correo.
     *
     * @return ReceivedInvoice|null La factura, o null si el adjunto no se acepta.
     */
    private function store(Attachment $attachment): ?ReceivedInvoice
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-mail-');
        file_put_contents($path, $attachment->contents());
        $file = new File($path);
        if (\count($this->validator->validate($file, InvoiceFileStore::constraint())) > 0) {
            unlink($path);

            return null;
        }

        $name = $attachment->filename() ?? 'adjunto.' . ($attachment->extension() ?? 'bin');
        // Antes de guardarlo: guardar lo mueve y $file deja de apuntar a nada.
        $mimeType = (string) $file->getMimeType();
        $invoice = new ReceivedInvoice(
            $this->files->storeFile($file, $name),
            mb_substr($name, 0, 255),
            $mimeType,
            ReceivedInvoice::SOURCE_EMAIL,
            null,
        );
        $this->em->persist($invoice);

        return $invoice;
    }
}
