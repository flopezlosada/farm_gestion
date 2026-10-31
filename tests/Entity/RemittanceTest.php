<?php

namespace App\Tests\Entity;

use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use PHPUnit\Framework\TestCase;

/**
 * La remesa como documento: el borrador del mes siguiente sale del anterior, no se
 * envía con recibos a medias o repetidos, y enviada ya no se toca.
 */
class RemittanceTest extends TestCase
{
    /** Un recibo completo, como los del Excel real. */
    private static function line(string $name, string $amount, string $debitReference, string $mandate = 'CUOTA MENSUAL 200'): RemittanceLine
    {
        return (new RemittanceLine())
            ->setDebtorName($name)
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference($mandate)
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference($debitReference)
            ->setSequenceType('FRST')
            ->setAmount($amount);
    }

    public function testElPeriodoEsSiempreElDiaUnoDelMes(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-08-17 13:45'), 'Recibos AGOSTO 2026', Remittance::SOURCE_APP, null);

        $this->assertSame('2026-08-01 00:00:00', $remittance->getPeriod()->format('Y-m-d H:i:s'));
    }

    public function testUnTipoDesconocidoNoSeAdmite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Remittance('sepa', new \DateTimeImmutable('2026-08-01'), 'x', Remittance::SOURCE_APP, null);
    }

    /**
     * El mes siguiente: los mismos recibos, en borrador, con el nombre y cada
     * referencia de adeudo pasados al mes nuevo. La anterior no cambia.
     */
    public function testElBorradorDelMesSiguienteCopiaLosRecibosYCambiaLasReferencias(): void
    {
        $august = Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-08-01'), 'Recibos AGOSTO 2026', '08. Recibos AGOSTO 2026.xlsx', [
            self::line('ANA LÓPEZ', '65.00', 'CUOTA AGOSTO 2026 200'),
            self::line('LUIS PÉREZ', '43.50', 'REC-202608-201', 'CUOTA MENSUAL 201'),
        ], null);

        $september = Remittance::nextFrom($august, new \DateTimeImmutable('2026-09-01'), null);

        $this->assertTrue($september->isDraft());
        $this->assertFalse($september->isImported());
        $this->assertSame('Recibos SEPTIEMBRE 2026', $september->getLabel());
        $this->assertSame(['CUOTA SEPTIEMBRE 2026 200', 'REC-202609-201'], array_map(static fn (RemittanceLine $l): string => $l->getDebitReference(), $september->getLines()->toArray()));
        $this->assertSame('108.50', $september->getTotal());
        $this->assertSame('CUOTA MENSUAL 201', $september->getLines()->last()->getMandateReference());
        $this->assertSame('CUOTA AGOSTO 2026 200', $august->getLines()->first()->getDebitReference(), 'La remesa anterior no se toca.');
        $this->assertNotSame($august->getLines()->first(), $september->getLines()->first());
    }

    public function testSinRecibosNoSeEnvia(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos SEPTIEMBRE 2026', Remittance::SOURCE_APP, null);

        $this->assertSame(['No tiene ningún recibo.'], $remittance->sendProblems());
    }

    /**
     * El banco rechaza la remesa entera por un recibo mal hecho: se avisa de lo que
     * falta en cada uno y de las referencias de adeudo repetidas (sin distinguir
     * mayúsculas ni espacios).
     */
    public function testLosProblemasDicenQueReciboYQueLeFalta(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos', Remittance::SOURCE_APP, null);
        $remittance->addLine(self::line('ANA LÓPEZ', '65.00', 'R-1'));
        $remittance->addLine(self::line('LUIS PÉREZ', '60.00', ' r-1 '));
        $remittance->addLine((new RemittanceLine())->setDebtorName('SIN DATOS')->setAmount('0'));

        $problems = $remittance->sendProblems();

        $this->assertContains('SIN DATOS: falta la cuenta.', $problems);
        $this->assertContains('SIN DATOS: falta la referencia del mandato.', $problems);
        $this->assertContains('SIN DATOS: falta la fecha de firma del mandato.', $problems);
        $this->assertContains('SIN DATOS: el importe tiene que ser mayor que cero.', $problems);
        $this->assertContains('La referencia de adeudo «R-1» está repetida: cada recibo necesita la suya.', $problems);
        $this->assertNotContains('ANA LÓPEZ: falta la cuenta.', $problems);
    }

    public function testLasReferenciasLargasNoCabenEnElBanco(): void
    {
        $line = self::line('ANA', '10.00', str_repeat('X', 36));

        $this->assertSame(['la referencia del adeudo pasa de 35 letras.'], $line->problems());
    }

    /** Enviada, ni se añaden ni se quitan ni se cambian recibos. */
    public function testUnaRemesaEnviadaNoSeCambia(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos', Remittance::SOURCE_APP, null);
        $line = self::line('ANA LÓPEZ', '65.00', 'R-1');
        $remittance->addLine($line);
        $remittance->markSent(null, new \DateTimeImmutable('2026-09-18'));

        $this->assertTrue($remittance->isSent());
        $this->assertNotNull($remittance->getSentAt());
        $this->assertFalse($remittance->isDeletable(), 'Una enviada desde la app es el registro de lo cobrado.');

        $attempts = [
            static fn () => $remittance->addLine(self::line('LUIS', '10.00', 'R-2')),
            static fn () => $remittance->removeLine($line),
            static fn () => $line->setAmount('1.00'),
            static fn () => $line->setIban('ES7921000813610123456789'),
            static fn () => $remittance->markSent(null, new \DateTimeImmutable('2026-09-18')),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail(sprintf('El intento %d debería haber fallado.', $i));
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('65.00', $line->getAmount());
    }

    public function testNoSeEnviaConProblemas(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos', Remittance::SOURCE_APP, null);

        $this->expectException(\LogicException::class);
        $remittance->markSent(null, new \DateTimeImmutable('2026-09-18'));
    }

    /**
     * Enviada lleva el día de cargo pedido, sin hora; es lo único que se cambia después
     * (se volvió a subir con otra fecha) o se quita (no vigilarla). En un borrador no.
     */
    public function testElDiaDeCargoSePoneAlEnviarYSeCambiaDespues(): void
    {
        $remittance = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-10-01'), 'Recibos', Remittance::SOURCE_APP, null);
        $remittance->addLine(self::line('ANA LÓPEZ', '65.00', 'R-1'));
        $this->assertNull($remittance->getChargeDate());

        try {
            $remittance->rescheduleCharge(new \DateTimeImmutable('2026-11-18'));
            $this->fail('En un borrador la fecha se pone al enviarla.');
        } catch (\LogicException) {
            $this->assertNull($remittance->getChargeDate());
        }

        $remittance->markSent(null, new \DateTimeImmutable('2026-11-18 17:45'));
        $this->assertSame('2026-11-18 00:00', $remittance->getChargeDate()?->format('Y-m-d H:i'));

        $remittance->rescheduleCharge(new \DateTimeImmutable('2026-11-25'));
        $this->assertSame('2026-11-25', $remittance->getChargeDate()?->format('Y-m-d'));
        $this->assertSame('65.00', $remittance->getTotal(), 'Cambiar la fecha no toca los recibos.');

        $remittance->rescheduleCharge(null);
        $this->assertNull($remittance->getChargeDate());
    }

    /** La importada entra como enviada, pero se puede tirar para volver a importarla. */
    public function testLaImportadaEntraEnviadaYSePuedeTirar(): void
    {
        $remittance = Remittance::fromImport(Remittance::KIND_MEMBERSHIP, new \DateTimeImmutable('2025-10-01'), '1ª Cuota SEMESTRAL 2025', '10. 1ª Cuota SEMESTRAL 2025.xlsx', [self::line('ANA', '25.00', 'S-1')], null);

        $this->assertTrue($remittance->isSent());
        $this->assertTrue($remittance->isImported());
        $this->assertNull($remittance->getChargeDate(), 'Del Excel no se sabe el día de cargo: no se vigila.');
        $this->assertTrue($remittance->isDeletable());
        $this->assertSame('10. 1ª Cuota SEMESTRAL 2025.xlsx', $remittance->getImportedFrom());
    }

    /** El total suma céntimos, no flotantes: 0,10 + 0,20 son 0,30 exactos. */
    public function testElTotalSumaCentimosExactos(): void
    {
        $remittance = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2026-09-01'), 'Derrama', Remittance::SOURCE_APP, null);
        $remittance->addLine(self::line('A', '0.10', 'D-1'));
        $remittance->addLine(self::line('B', '0.20', 'D-2'));
        $remittance->addLine(self::line('C', '43.50', 'D-3'));

        $this->assertSame('43.80', $remittance->getTotal());
    }

    public function testElIbanSeGuardaSinEspaciosYEnMayusculas(): void
    {
        $line = (new RemittanceLine())->setIban(' es91 2100 0418 4502 0005 1332 ');

        $this->assertSame('ES9121000418450200051332', $line->getIban());
        $this->assertSame('ES91 … 1332', $line->getMaskedIban());
    }
}
