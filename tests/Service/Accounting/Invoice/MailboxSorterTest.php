<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Service\Accounting\Invoice\MailboxSorter;
use App\Service\Accounting\Invoice\MailedInvoiceIntake;
use App\Service\Accounting\OfficialNotice\NoticeOutcome;
use App\Service\Accounting\OfficialNotice\OfficialNoticeIntake;
use PHPUnit\Framework\TestCase;

/**
 * Cada correo del buzón a su sitio: una factura sigue a la bandeja de facturas como
 * siempre; lo que dice venir de un organismo oficial, auténtico o no, nunca.
 */
class MailboxSorterTest extends TestCase
{
    /** Un correo que no es de ningún organismo llega a la bandeja de facturas, con lo que deje. */
    public function testLaFacturaSigueSuCamino(): void
    {
        $invoices = $this->createMock(MailedInvoiceIntake::class);
        $invoices->expects($this->once())->method('take')->with('correo-de-factura')->willReturn(2);

        $this->assertSame(2, $this->sorter(NoticeOutcome::NotOfficial, $invoices)->take('correo-de-factura'));
    }

    /** Avisado, repetido o sospechoso: ninguno va a la bandeja de facturas, y no deja ninguna. */
    public function testLoOficialNoEsFactura(): void
    {
        foreach ([NoticeOutcome::Alerted, NoticeOutcome::Repeated, NoticeOutcome::Suspicious] as $outcome) {
            $invoices = $this->createMock(MailedInvoiceIntake::class);
            $invoices->expects($this->never())->method('take');

            $this->assertSame(0, $this->sorter($outcome, $invoices)->take('aviso'), $outcome->name);
        }
    }

    /**
     * El reparto con el resultado de los avisos oficiales ya decidido.
     *
     * @param NoticeOutcome       $outcome  Qué dirían los avisos oficiales del correo.
     * @param MailedInvoiceIntake $invoices La bandeja de facturas.
     */
    private function sorter(NoticeOutcome $outcome, MailedInvoiceIntake $invoices): MailboxSorter
    {
        $notices = $this->createStub(OfficialNoticeIntake::class);
        $notices->method('take')->willReturn($outcome);

        return new MailboxSorter($notices, $invoices);
    }
}
