<?php

namespace App\Service\Telegram\Destination;

use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramInput;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Facturas y tickets → la bandeja de facturas recibidas.
 *
 * Deja la factura en la misma cola que la subida por la web, con las mismas reglas
 * de formato y tamaño, y la lee en el momento para contarle a quien la mandó lo
 * que ha entendido (proveedor, importe, fecha). Lo demás (proponer, confirmar) es
 * lo de siempre: el bot no sabe nada de contabilidad.
 *
 * Deshacer la quita de la bandeja mientras nadie la haya anotado. Anotada, ya es
 * un apunte del libro, y eso se corrige en la web, no desde el móvil.
 *
 * Puede mandar facturas cualquiera que esté vinculado, tenga o no permiso de
 * contabilidad: es lo que hace un trabajador con el ticket de la gasolina. Mandar
 * no es anotar; anotar sigue pidiendo el permiso, en la web.
 */
class InvoiceDestination implements TelegramDestination
{
    /**
     * @param InvoiceFileStore       $files     Archivo de facturas.
     * @param InvoiceReadQueue       $queue     Para leerla ya.
     * @param ValidatorInterface     $validator Para aplicar las reglas de la subida web.
     * @param EntityManagerInterface $em        Para guardar.
     * @param AppSettings            $settings  Para saber si contabilidad está encendida.
     * @param LoggerInterface        $logger    Rastro de lo que falla.
     */
    public function __construct(
        private readonly InvoiceFileStore $files,
        private readonly InvoiceReadQueue $queue,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'invoice';
    }

    public function section(): string
    {
        return 'contabilidad';
    }

    public function label(): string
    {
        return 'Factura recibida';
    }

    public function description(): string
    {
        return 'Factura, factura simplificada o ticket de compra emitido por un proveedor o comercio, con importe a pagar (gasolina, ferretería, teléfono, alquiler…).';
    }

    public function accepts(TelegramInput $input, User $user): bool
    {
        return \in_array($input->kind, [TelegramInput::PHOTO, TelegramInput::DOCUMENT], true)
            && $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
    }

    public function help(User $user): ?string
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)) {
            return null;
        }

        return 'Facturas y tickets: mándame la foto o el PDF. Si la foto sale borrosa, mándala como archivo (clip → Archivo), que así Telegram no la comprime.';
    }

    public function commit(TelegramInput $input, User $user): TelegramOutcome
    {
        try {
            $path = $input->localPath();
        } catch (TelegramApiException $e) {
            $this->logger->warning('Telegram: no se pudo descargar una factura', ['error' => $e->getMessage()]);

            throw new TelegramRejected($e->getMessage());
        }

        $file = new File($path);
        $violations = $this->validator->validate($file, InvoiceFileStore::constraint());
        if (\count($violations) > 0) {
            throw new TelegramRejected((string) $violations->get(0)->getMessage());
        }

        $name = (string) $input->fileName;
        // Antes de guardarlo: guardar lo mueve y $file deja de apuntar a nada.
        $mimeType = (string) $file->getMimeType();
        $invoice = new ReceivedInvoice(
            $this->files->storeFile($file, $name),
            mb_substr($name, 0, 255),
            $mimeType,
            ReceivedInvoice::SOURCE_TELEGRAM,
            $user,
        );
        $this->em->persist($invoice);
        $this->em->flush();

        // Se lee ya, para poder contar lo leído. Si falla (Gemini saturado, sin
        // clave), la cola la reintenta sola y aquí se dice sin más.
        $this->queue->process(1, [(int) $invoice->getId()]);
        $this->em->refresh($invoice);

        if ($invoice->getStatus() === ReceivedInvoice::STATUS_READ && $invoice->getDocumentType() === ExtractedInvoice::TYPE_OTHER) {
            // Leída, y quien la leyó dice que no es una factura (un cartel, una foto
            // cualquiera): se pregunta en vez de dar por buena la bandeja.
            $what = $this->described($invoice);

            return new TelegramOutcome(
                (string) $invoice->getId(),
                sprintf('No parecía una factura%s, pero está guardada en la bandeja de facturas.', $what),
                sprintf('Esto no parece una factura%s. ¿La guardo igualmente en la bandeja de facturas?', $what),
            );
        }

        return new TelegramOutcome((string) $invoice->getId(), $this->summarize($invoice));
    }

    /** Lo que ha visto quien la leyó, entre paréntesis, si lo ha dicho. */
    private function described(ReceivedInvoice $invoice): string
    {
        $concept = trim((string) $invoice->getConcept());

        return $concept !== '' ? sprintf(' («%s»)', mb_strimwidth($concept, 0, 80, '…')) : '';
    }

    public function undo(string $ref): ?string
    {
        // Con bloqueo: si en la web la están confirmando ahora mismo, se espera a que
        // acabe y se ve ya anotada, en vez de borrar una factura con su apunte.
        $invoice = $this->em->find(ReceivedInvoice::class, (int) $ref, LockMode::PESSIMISTIC_WRITE);
        if ($invoice === null) {
            return null;
        }
        if (!$invoice->isOpen()) {
            // Anotada o descartada: alguien ya la ha resuelto en la web, y eso manda.
            return $invoice->getStatus() === ReceivedInvoice::STATUS_DISCARDED
                ? 'Esa factura ya la han descartado desde la web.'
                : 'Esa factura ya está anotada en el libro. Corrígela desde la web.';
        }

        $fileName = $invoice->getFileName();
        $this->em->remove($invoice);
        $this->em->flush();
        // El fichero, después de quitar la fila: si quitarla falla, la factura sigue
        // entera. (La copia original la conserva el envío del bot.)
        $this->files->discard($fileName);

        return null;
    }

    /**
     * Lo que se ha entendido de la factura, en una frase.
     *
     * @param ReceivedInvoice $invoice Recién guardada (y leída, si se pudo).
     */
    private function summarize(ReceivedInvoice $invoice): string
    {
        if ($invoice->getStatus() === ReceivedInvoice::STATUS_PENDING) {
            return sprintf('Factura «%s» guardada en la bandeja. Aún no la he podido leer; se leerá sola.', $invoice->getOriginalName());
        }
        if ($invoice->getStatus() !== ReceivedInvoice::STATUS_READ) {
            return sprintf('Factura «%s» guardada en la bandeja, pero no he podido leerla: habrá que completarla a mano en la web.', $invoice->getOriginalName());
        }

        $provider = $invoice->getProviderName();
        $total = $invoice->getTotal();
        if ($provider === null && $total === null) {
            return sprintf('Factura «%s» guardada en la bandeja, pero no he sacado ni el proveedor ni el importe: revísala en la web.', $invoice->getOriginalName());
        }

        $what = $provider !== null ? 'Factura de ' . $provider : 'Factura';
        if ($total !== null) {
            $what .= ', ' . number_format((float) $total, 2, ',', '.') . ' €';
        }
        if ($invoice->getInvoiceDate() !== null) {
            $what .= ', del ' . $invoice->getInvoiceDate()->format('d/m/Y');
        }

        return $what . '. Guardada en la bandeja de facturas.';
    }
}
