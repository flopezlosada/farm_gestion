<?php

namespace App\Service\Telegram\Destination;

use App\Entity\ReceivedInvoice;
use App\Entity\TelegramLink;
use App\Entity\User;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramChat;
use App\Service\Telegram\TelegramInput;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Facturas y tickets → la bandeja de facturas recibidas.
 *
 * Deja la factura en la misma cola que la subida por la web, con las mismas reglas
 * de formato y tamaño, y la manda leer en el momento. Lo demás (proponer,
 * confirmar) es lo de siempre: el bot no sabe nada de contabilidad.
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

    public function handle(TelegramInput $input, TelegramLink $link, TelegramChat $chat): void
    {
        try {
            $path = $input->localPath();
        } catch (TelegramApiException $e) {
            $this->logger->warning('Telegram: no se pudo descargar una factura', ['error' => $e->getMessage()]);
            $chat->say($e->getMessage());

            return;
        }

        $file = new File($path);
        $violations = $this->validator->validate($file, InvoiceFileStore::constraint());
        if (\count($violations) > 0) {
            $chat->say((string) $violations->get(0)->getMessage());

            return;
        }

        $name = (string) $input->fileName;
        // Antes de guardarlo: guardar lo mueve y $file deja de apuntar a nada.
        $mimeType = (string) $file->getMimeType();
        $invoice = new ReceivedInvoice(
            $this->files->storeFile($file, $name),
            mb_substr($name, 0, 255),
            $mimeType,
            ReceivedInvoice::SOURCE_TELEGRAM,
            $link->getUser(),
        );
        $this->em->persist($invoice);
        $this->em->flush();

        $chat->say(sprintf('Recibido «%s». Ya está en la bandeja de facturas; ahora se lee sola.', $invoice->getOriginalName()));

        // Quien manda ya tiene su respuesta; leer tarda unos segundos y, si falla,
        // la cola lo reintenta sola.
        $this->queue->process(1, [(int) $invoice->getId()]);
    }
}
