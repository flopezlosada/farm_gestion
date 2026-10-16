<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Service\Accounting\Invoice\InvoiceQuarter;
use PHPUnit\Framework\TestCase;

/**
 * El trimestre que se entrega a la gestoría: sus días, su nombre y cuál toca.
 */
class InvoiceQuarterTest extends TestCase
{
    /** Cada trimestre va del primer día de su primer mes al último del tercero. */
    public function testLimitesDelTrimestre(): void
    {
        $q = InvoiceQuarter::of(2026, 1);
        $this->assertSame('2026-01-01', $q->from()->format('Y-m-d'));
        $this->assertSame('2026-03-31', $q->to()->format('Y-m-d'));

        $q = InvoiceQuarter::of(2024, 4);
        $this->assertSame('2024-10-01', $q->from()->format('Y-m-d'));
        $this->assertSame('2024-12-31', $q->to()->format('Y-m-d'));
        $this->assertSame('4T2024', $q->label(), 'Como se llaman hoy las carpetas de envío a la gestoría.');
    }

    /** Toca entregar el último trimestre cerrado; en enero, el cuarto del año anterior. */
    public function testElUltimoCerrado(): void
    {
        $this->assertSame('3T2026', InvoiceQuarter::lastClosed(new \DateTimeImmutable('2026-10-07'))->label());
        $this->assertSame('3T2026', InvoiceQuarter::lastClosed(new \DateTimeImmutable('2026-12-31'))->label());
        $this->assertSame('4T2025', InvoiceQuarter::lastClosed(new \DateTimeImmutable('2026-01-15'))->label());
        $this->assertSame('1T2026', InvoiceQuarter::lastClosed(new \DateTimeImmutable('2026-04-01'))->label());
    }

    /** Los trimestrales se presentan hasta el 20 del mes siguiente; el cuarto, hasta el 30 de enero. */
    public function testElPlazoDeEntrega(): void
    {
        $this->assertSame('2026-04-20', InvoiceQuarter::of(2026, 1)->deliveryDeadline()->format('Y-m-d'));
        $this->assertSame('2026-10-20', InvoiceQuarter::of(2026, 3)->deliveryDeadline()->format('Y-m-d'));
        $this->assertSame('2027-01-30', InvoiceQuarter::of(2026, 4)->deliveryDeadline()->format('Y-m-d'));
    }

    /** El trimestre de un día: sus bordes caen dentro. */
    public function testElTrimestreDeUnDia(): void
    {
        $this->assertSame('3T2026', InvoiceQuarter::containing(new \DateTimeImmutable('2026-07-01'))->label());
        $this->assertSame('3T2026', InvoiceQuarter::containing(new \DateTimeImmutable('2026-09-30'))->label());
        $this->assertSame('4T2026', InvoiceQuarter::containing(new \DateTimeImmutable('2026-10-01'))->label());
        $this->assertSame('1T2027', InvoiceQuarter::containing(new \DateTimeImmutable('2027-01-01'))->label());
    }

    /** Un trimestre que no existe no se puede construir. */
    public function testUnTrimestreImposibleNoExiste(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceQuarter::of(2026, 5);
    }
}
