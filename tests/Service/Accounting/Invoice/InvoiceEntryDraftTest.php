<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Repository\FinancialAccountRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\InvoiceEntryDraft;
use PHPUnit\Framework\TestCase;

/**
 * La cuenta que propone el apunte de una factura recibida, sin base de datos: las
 * cuentas activas y el historial del proveedor los dan los repositorios.
 */
class InvoiceEntryDraftTest extends TestCase
{
    private const TAX_ID = 'B12345678';

    private int $nextId = 1;

    private FinancialAccount $fiare;
    private FinancialAccount $box;
    private FinancialAccount $card;

    protected function setUp(): void
    {
        $this->fiare = $this->account('Fiare', FinancialAccount::KIND_BANK);
        $this->box = $this->account('Caja local', FinancialAccount::KIND_CASH);
        $this->card = $this->account('Tarjeta Fiare', FinancialAccount::KIND_CARD);
    }

    /**
     * Pagado con tarjeta va a la tarjeta, aunque a ese proveedor se le pagara otras
     * veces desde el banco: así están las compras con tarjeta de antes de tener cuenta.
     */
    public function testConTarjetaVaALaTarjetaAunqueOtrasVecesFueraElBanco(): void
    {
        $draft = $this->draft([$this->fiare, $this->box, $this->card], usual: $this->fiare->getId());

        $this->assertSame($this->card, $draft->for($this->ticket(ReceivedInvoice::PAYMENT_CARD))->getAccount());
    }

    /** Sin cuenta de tarjeta, lo pagado con tarjeta sigue yendo al banco, como antes. */
    public function testSinCuentaDeTarjetaVaAlBanco(): void
    {
        $draft = $this->draft([$this->fiare, $this->box]);

        $this->assertSame($this->fiare, $draft->for($this->ticket(ReceivedInvoice::PAYMENT_CARD))->getAccount());
    }

    /**
     * Lo demás no cambia: manda la cuenta de otras veces y, si el proveedor es nuevo,
     * la forma de pago (efectivo a la caja, sin leer al banco). Nunca la tarjeta.
     */
    public function testSinTarjetaMandaElHistorialYLuegoLaFormaDePago(): void
    {
        $accounts = [$this->card, $this->fiare, $this->box];

        $this->assertSame($this->box, $this->draft($accounts, usual: $this->box->getId())
            ->for($this->ticket(ReceivedInvoice::PAYMENT_TRANSFER))->getAccount());
        $this->assertSame($this->box, $this->draft($accounts)->for($this->ticket(ReceivedInvoice::PAYMENT_CASH))->getAccount());
        $this->assertSame($this->fiare, $this->draft($accounts)->for($this->ticket(ReceivedInvoice::PAYMENT_UNKNOWN))->getAccount());
    }

    /**
     * @param list<FinancialAccount> $accounts activas
     */
    private function draft(array $accounts, ?int $usual = null): InvoiceEntryDraft
    {
        $accountRepo = $this->createStub(FinancialAccountRepository::class);
        $accountRepo->method('findActive')->willReturn($accounts);
        $invoices = $this->createStub(ReceivedInvoiceRepository::class);
        $invoices->method('usualAccountIdFor')->willReturn($usual);

        return new InvoiceEntryDraft($accountRepo, $invoices);
    }

    /** Un ticket de ferretería tal como lo deja la lectura, con los campos que no trae a null. */
    private function ticket(string $paymentMethod): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('ticket.jpg', 'ticket.jpg', 'image/jpeg', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(new ExtractedInvoice(
            documentType: 'ticket',
            date: new \DateTimeImmutable('2026-09-14'),
            providerName: 'Ferretería de prueba',
            providerTaxId: self::TAX_ID,
            invoiceNumber: null,
            total: '18.45',
            taxLines: [],
            withholding: null,
            withholdingRate: null,
            providerAddress: null,
            providerPostalCode: null,
            providerTown: null,
            providerProvince: null,
            concept: 'Tornillería',
            paymentMethod: $paymentMethod,
            confidence: null,
            categoryId: null,
        ), 'test', null, new \DateTimeImmutable('2026-09-14'));

        return $invoice;
    }

    private function account(string $name, int $kind): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind($kind);
        (new \ReflectionProperty(FinancialAccount::class, 'id'))->setValue($account, $this->nextId++);

        return $account;
    }
}
