<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnOutcome;
use App\Entity\ReturnStatus;
use PHPUnit\Framework\TestCase;

/**
 * Un recibo devuelto como deuda: abierta hasta que se elige una salida, y cada salida
 * con lo que la sostiene (el pago, el recibo nuevo). Lo que no cuadra no se deja
 * representar: devolver un recibo de un borrador, ligar un apunte de otro importe o
 * cambiar de salida cuando ya hay un recibo nuevo que lo cobra.
 */
class RemittanceReturnTest extends TestCase
{
    private const IBAN = 'ES9121000418450200051332';
    private const OTHER_IBAN = 'ES7921000813610123456789';

    public function testNaceComoDeudaAbierta(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), $this->entry('-55.00'), null);

        $this->assertSame(ReturnStatus::Pending, $return->getStatus());
        $this->assertTrue($return->getStatus()->isOpen());
        $this->assertNull($return->getOutcome());
    }

    public function testSinApunteEsUnReciboRechazadoAntesDeCobrarse(): void
    {
        $this->assertSame(ReturnStatus::Pending, (new RemittanceReturn($this->sentLine('55.00'), null, null))->getStatus());
    }

    public function testUnReciboDeUnBorradorNoSeDevuelve(): void
    {
        $draft = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-08-01'), 'Borrador', Remittance::SOURCE_APP, null);
        $draft->addLine($line = $this->line('55.00'));

        $this->expectException(\InvalidArgumentException::class);
        new RemittanceReturn($line, null, null);
    }

    /** 36 € devueltos no son el recibo de 55, ni un ingreso de 55 es una devolución. */
    public function testLaDevolucionEsUnCargoPorElImporteDelRecibo(): void
    {
        foreach (['-36.00', '55.00'] as $amount) {
            try {
                new RemittanceReturn($this->sentLine('55.00'), $this->entry($amount), null);
                $this->fail(sprintf('Un apunte de %s no es la devolución de un recibo de 55.', $amount));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVolverACobrarEsperaAlBorradorYSeCierraAlEnviarlo(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);
        $return->recharge(null);
        $this->assertSame(ReturnStatus::ToRecharge, $return->getStatus());

        $draft = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-09-01'), 'Septiembre', Remittance::SOURCE_APP, null);
        $draft->addLine($retry = RemittanceLine::retryOf($return->getLine()));
        $return->rechargeIn($retry);
        $this->assertSame(ReturnStatus::Recharging, $return->getStatus());

        $draft->markSent(null);
        $this->assertSame(ReturnStatus::Closed, $return->getStatus());
        $this->assertSame(ReturnOutcome::Recharge, $return->getOutcome());
    }

    public function testSoloSeVuelveACobrarEnUnBorradorYSiEstaPorCobrar(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);
        $draft = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-09-01'), 'Septiembre', Remittance::SOURCE_APP, null);
        $draft->addLine($retry = RemittanceLine::retryOf($return->getLine()));

        try {
            $return->rechargeIn($retry);
            $this->fail('Pendiente todavía no se ha decidido volver a cobrarlo.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $return->recharge(null);
        $this->expectException(\LogicException::class);
        $return->rechargeIn($this->sentLine('55.00'));
    }

    /** Con un recibo nuevo ya cobrándolo, otra salida haría cobrarlo dos veces. */
    public function testConUnReciboNuevoNoSeCambiaDeSalida(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);
        $return->recharge(null);
        $draft = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-09-01'), 'Septiembre', Remittance::SOURCE_APP, null);
        $draft->addLine($retry = RemittanceLine::retryOf($return->getLine()));
        $return->rechargeIn($retry);

        foreach ([$return->reopen(...), fn () => $return->notOwed(null), fn () => $return->paidWith($this->entry('55.00'), null)] as $change) {
            try {
                $change();
                $this->fail('Había un recibo nuevo cobrándolo.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(ReturnStatus::Recharging, $return->getStatus());
    }

    public function testPagadoAparteConUnIngresoDeSuImporte(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);

        try {
            $return->paidWith($this->entry('36.00'), null);
            $this->fail('Un ingreso de 36 no paga un recibo de 55.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(ReturnStatus::Pending, $return->getStatus());
        }

        $return->paidWith($payment = $this->entry('55.00'), null);
        $this->assertSame(ReturnStatus::Closed, $return->getStatus());
        $this->assertSame($payment, $return->getPaidWith());
        $this->assertNotNull($return->getResolvedAt());
    }

    public function testDeshacerLaVuelveAAbrir(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);
        $return->paidWith($this->entry('55.00'), null);
        $return->reopen();

        $this->assertSame(ReturnStatus::Pending, $return->getStatus());
        $this->assertNull($return->getPaidWith());
        $this->assertNull($return->getResolvedAt());

        $return->writeOff(null);
        $this->assertSame(ReturnStatus::Closed, $return->getStatus());
        $this->assertSame(ReturnOutcome::WrittenOff, $return->getOutcome());
    }

    /** Cambiar de «pagado aparte» a otra salida suelta el pago: no quedan las dos. */
    public function testOtraSalidaSueltaElPago(): void
    {
        $return = new RemittanceReturn($this->sentLine('55.00'), null, null);
        $return->paidWith($this->entry('55.00'), null);
        $return->notOwed(null);

        $this->assertNull($return->getPaidWith());
        $this->assertSame(ReturnOutcome::NotOwed, $return->getOutcome());
    }

    public function testElReciboNuevoLlevaDevEnLaReferenciaYCabeEnElBanco(): void
    {
        $retry = RemittanceLine::retryOf($this->line('55.00', 'AGOSTO 2095 901'));
        $this->assertSame('AGOSTO 2095 901 DEV', $retry->getDebitReference());
        $this->assertNull($retry->getRemittance());

        $long = RemittanceLine::retryOf($this->line('55.00', str_repeat('X', RemittanceLine::MAX_REFERENCE)));
        $this->assertSame(RemittanceLine::MAX_REFERENCE, mb_strlen($long->getDebitReference()));
        $this->assertStringEndsWith(' DEV', $long->getDebitReference());
    }

    /** El recibo que vuelve a cobrar uno devuelto no pasa al mes siguiente: sería cobrarlo dos veces. */
    public function testElBorradorSiguienteNoCopiaElReciboDev(): void
    {
        $september = new Remittance(Remittance::KIND_OTHER, new \DateTimeImmutable('2095-09-01'), 'Septiembre', Remittance::SOURCE_APP, null);
        $september->addLine($this->line('55.00', 'SEPTIEMBRE 2095 901'));
        $september->addLine($retry = RemittanceLine::retryOf($this->line('55.00', 'AGOSTO 2095 901')));
        $this->assertTrue($retry->isRetry());
        $this->assertSame('AGOSTO 2095 901 DEV', RemittanceLine::retryOf($retry)->getDebitReference(), 'Devuelto otra vez, no repite «DEV».');

        $october = Remittance::nextFrom($september, new \DateTimeImmutable('2095-10-01'), null);

        $this->assertSame(['OCTUBRE 2095 901'], $october->getLines()->map(static fn (RemittanceLine $l): string => $l->getDebitReference())->getValues());
    }

    /**
     * Si la ficha tiene el mismo mandato, la cuenta sale de la ficha (lo normal es que
     * se haya corregido allí); con otro mandato la ficha puede ser de otra persona.
     */
    public function testLaCuentaCorregidaSaleDeLaFichaSoloConElMismoMandato(): void
    {
        $line = $this->line('55.00');
        $line->setPartner((new Partner())->setIban(self::OTHER_IBAN)->setMandateReference('CUOTA MENSUAL 901')->setMandateSignedOn(new \DateTimeImmutable('2022-12-01')));
        $this->assertSame(self::OTHER_IBAN, RemittanceLine::retryOf($line)->getIban());

        $line->getPartner()->setMandateReference('CUOTA MENSUAL 777');
        $this->assertSame(self::IBAN, RemittanceLine::retryOf($line)->getIban());
    }

    private function line(string $amount, string $reference = 'AGOSTO 2095 901'): RemittanceLine
    {
        return (new RemittanceLine())->setDebtorName('ZZTEST LUIS')->setIban(self::IBAN)
            ->setMandateReference('CUOTA MENSUAL 901')->setMandateSignedOn(new \DateTimeImmutable('2022-12-01'))
            ->setDebitReference($reference)->setSequenceType(RemittanceLine::SEQUENCE_FIRST)->setAmount($amount);
    }

    /** Un recibo de una remesa ya enviada. */
    private function sentLine(string $amount): RemittanceLine
    {
        $line = $this->line($amount);
        Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2095-08-01'), 'Agosto', 'agosto.xlsx', [$line], null);

        return $line;
    }

    private function entry(string $amount): AccountEntry
    {
        return (new AccountEntry())->setDate(new \DateTimeImmutable('2095-08-19'))->setConcept('bel-liq.rem.devol20950016561')->setAmount($amount);
    }
}
