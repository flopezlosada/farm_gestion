<?php

namespace App\Service\Telegram\Destination;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\User;
use App\Service\Accounting\IssuedInvoice\DictatedInvoice;
use App\Service\Accounting\IssuedInvoice\DictatedInvoiceReader;
use App\Service\Ai\GeminiException;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramInput;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Encargos de factura dictados por voz o escritos → un BORRADOR de factura emitida.
 *
 * El bot nunca emite: deja el borrador, sin número, y se revisa y se emite en la
 * web. Así una mala transcripción no deja nunca un número gastado ni una factura
 * que haya que rectificar.
 *
 * Deshacer tira el borrador mientras siga siéndolo. Emitida, ya sólo se rectifica,
 * y eso se hace en la web.
 */
class IssuedInvoiceDestination implements TelegramDestination
{
    /**
     * @param DictatedInvoiceReader  $reader   Quien entiende el encargo.
     * @param EntityManagerInterface $em       Para guardar.
     * @param AppSettings            $settings Para saber si contabilidad está encendida y el motivo de exención.
     * @param LoggerInterface        $logger   Rastro de lo que falla.
     */
    public function __construct(
        private readonly DictatedInvoiceReader $reader,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'issued_invoice';
    }

    public function section(): string
    {
        return 'contabilidad';
    }

    public function label(): string
    {
        return 'Factura emitida (borrador)';
    }

    public function description(): string
    {
        return 'Encargo de HACER una factura que la asociación cobra a un cliente (formación, visitas, colaboraciones), dicho por voz o escrito: a quién, por qué y cuánto. No es una factura ni un ticket que nos llega de un proveedor.';
    }

    public function accepts(TelegramInput $input, User $user): bool
    {
        return Dictation::isDictated($input)
            && $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)
            && $this->reader->isAvailable();
    }

    public function help(User $user): ?string
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD) || !$this->reader->isAvailable()) {
            return null;
        }

        return 'Facturas que hacemos nosotras: dime por voz o por escrito a quién, por qué y cuánto («factura a IMIDRA, 20 horas de formación, 1.600 €, exenta») y te dejo el borrador para revisarlo y emitirlo en la web.';
    }

    public function commit(TelegramInput $input, User $user): TelegramOutcome
    {
        try {
            $dictated = $this->reader->read(
                Dictation::parts($input, $this->logger),
                $this->em->getRepository(Customer::class)->findBy([], ['name' => 'ASC']),
                new \DateTimeImmutable('today'),
                $user,
            );
        } catch (GeminiException $e) {
            $this->logger->warning('Telegram: no se pudo entender un encargo de factura', ['error' => $e->getMessage()]);
            throw new TelegramRejected('Ahora mismo no puedo entenderlo. Mándamelo otra vez en un rato, o haz la factura en la web.');
        }

        $invoice = $dictated->invoice;
        if ($invoice->getLines()->isEmpty()) {
            throw new TelegramRejected('No he sacado ningún concepto con su importe. Dime a quién, por qué y cuánto: «factura a IMIDRA, 20 horas de formación, 1.600 €, exenta».');
        }
        $invoice->fillExemptionNote($this->settings->getString(AppSettings::ISSUED_INVOICE_EXEMPTION));
        $this->em->persist($invoice);
        $this->em->flush();

        return new TelegramOutcome((string) $invoice->getId(), $this->summarize($dictated));
    }

    public function undo(string $ref): ?string
    {
        // Con bloqueo: si en la web la están emitiendo ahora mismo, se espera a que
        // acabe y se ve ya emitida, en vez de tirar una factura con número.
        $invoice = $this->em->find(IssuedInvoice::class, (int) $ref, LockMode::PESSIMISTIC_WRITE);
        if ($invoice === null) {
            return null;
        }
        if ($invoice->isIssued()) {
            return sprintf('Esa factura ya está emitida (%s). Si hay que anularla, se rectifica desde la web.', $invoice->getNumberLabel());
        }
        $this->em->remove($invoice);
        $this->em->flush();

        return null;
    }

    /**
     * Lo entendido, en una frase: a quién, cuánto, de qué fecha y qué falta.
     *
     * @param DictatedInvoice $dictated Recién guardado.
     */
    private function summarize(DictatedInvoice $dictated): string
    {
        $invoice = $dictated->invoice;
        $lines = $invoice->getLines()->count();
        $what = sprintf(
            'Borrador de factura %s%s: %d %s, %s € con IVA, del %s.',
            $invoice->getCustomer() !== null ? 'para ' . $invoice->getCustomer()->getName() : 'sin cliente',
            ' (' . mb_strtolower($invoice->getSeriesLabel()) . ')',
            $lines,
            $lines === 1 ? 'línea' : 'líneas',
            number_format((float) $invoice->getTotal(), 2, ',', '.'),
            $invoice->getIssueDate()->format('d/m/Y'),
        );
        if ($invoice->getCustomer() === null) {
            $what .= $dictated->customerHeard !== null
                ? sprintf(' «%s» no está entre los clientes: dalo de alta y elígelo en el borrador.', $dictated->customerHeard)
                : ' No he sabido a quién: elige el cliente en el borrador.';
        }

        return $what . ' Sin número todavía: revísalo y emítelo en la web, en Contabilidad → Facturas emitidas.';
    }
}
