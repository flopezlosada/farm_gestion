<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\AccountEntry;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\FinancialAccount;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Service\ConsumerGroup\ConsumerGroupReceiptSync;
use PHPUnit\Framework\TestCase;

/**
 * Cómo queda pagado un pedido del grupo de consumo según lo que diga el banco de
 * su recibo ({@see ConsumerGroupReceiptSync::outcome()}).
 */
class ConsumerGroupReceiptSyncTest extends TestCase
{
    private const CREDIT_DAY = '2026-11-06';

    private int $nextId = 1;

    public function testEnviadaSinAbonoNoEstaPagadoNiDevuelto(): void
    {
        $line = $this->sentLine();

        self::assertSame(['via' => null, 'at' => null, 'returned' => false], ConsumerGroupReceiptSync::outcome($line, [], self::noCredit()));
    }

    public function testConElAbonoEnElLibroQuedaCobradoPorReciboEseDia(): void
    {
        $outcome = ConsumerGroupReceiptSync::outcome($this->sentLine(), [], self::credited());

        self::assertSame(ConsumerGroupPaymentMethod::Receipt, $outcome['via']);
        self::assertSame(self::CREDIT_DAY, $outcome['at']?->format('Y-m-d'));
        self::assertFalse($outcome['returned']);
    }

    /**
     * La devolución suele llegar días DESPUÉS del abono: aunque la remesa esté
     * abonada, ese pedido vuelve a deberse.
     */
    public function testDevueltoDespuesDelAbonoQuedaDevuelto(): void
    {
        $line = $this->sentLine();
        $return = new RemittanceReturn($line, null, null);

        self::assertSame(['via' => null, 'at' => null, 'returned' => true], ConsumerGroupReceiptSync::outcome($line, [$line->getId() => $return], self::credited()));
    }

    public function testDevolucionCerradaSinPagoSigueDevuelto(): void
    {
        $line = $this->sentLine();
        $lost = new RemittanceReturn($line, null, null);
        $lost->writeOff(null);

        self::assertTrue(ConsumerGroupReceiptSync::outcome($line, [$line->getId() => $lost], self::credited())['returned']);
    }

    public function testPagadoAparteEnCajaEsMetalicoYEnBancoTransferencia(): void
    {
        foreach ([FinancialAccount::KIND_CASH => ConsumerGroupPaymentMethod::Cash, FinancialAccount::KIND_BANK => ConsumerGroupPaymentMethod::Transfer] as $kind => $method) {
            $line = $this->sentLine();
            $return = new RemittanceReturn($line, null, null);
            $return->payApart(self::income('10.00', '2026-11-20', $kind), null);

            $outcome = ConsumerGroupReceiptSync::outcome($line, [$line->getId() => $return], self::credited());

            self::assertSame($method, $outcome['via']);
            self::assertSame('2026-11-20', $outcome['at']?->format('Y-m-d'));
            self::assertFalse($outcome['returned']);
        }
    }

    /**
     * Vuelto a cobrar: manda el recibo nuevo. En un borrador «va en el recibo»;
     * enviado, espera a SU abono; abonado, cobrado.
     */
    public function testVueltoACobrarSigueAlReciboNuevo(): void
    {
        $line = $this->sentLine();
        $return = new RemittanceReturn($line, null, null);
        $return->recharge(null);
        $draft = new Remittance(Remittance::KIND_CONSUMER_GROUP, new \DateTimeImmutable('2026-11-01'), 'Grupo de consumo NOVIEMBRE 2026', Remittance::SOURCE_APP, null);
        $retry = $this->withId(RemittanceLine::retryOf($line));
        $draft->addLine($retry);
        $return->rechargeIn($retry);
        $returns = [$line->getId() => $return];

        self::assertSame(['via' => null, 'at' => null, 'returned' => false], ConsumerGroupReceiptSync::outcome($line, $returns, self::credited()), 'En el borrador vuelve a ir en el recibo.');

        $draft->markSent(null, new \DateTimeImmutable('2026-12-05'));
        $onlyOld = static fn (Remittance $r): ?\DateTimeImmutable => $r === $draft ? null : new \DateTimeImmutable(self::CREDIT_DAY);
        self::assertSame(['via' => null, 'at' => null, 'returned' => false], ConsumerGroupReceiptSync::outcome($line, $returns, $onlyOld), 'El abono de la remesa vieja no cobra el recibo nuevo.');

        $outcome = ConsumerGroupReceiptSync::outcome($line, $returns, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-12-07'));
        self::assertSame(ConsumerGroupPaymentMethod::Receipt, $outcome['via']);
        self::assertSame('2026-12-07', $outcome['at']?->format('Y-m-d'));
    }

    /** El recibo de una remesa del grupo de consumo enviada, con id. */
    private function sentLine(): RemittanceLine
    {
        $payer = (new Partner())->setname('Ana')->setSurname('López')
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference('CUOTA MENSUAL 200')
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $remittance = new Remittance(Remittance::KIND_CONSUMER_GROUP, new \DateTimeImmutable('2026-10-01'), 'Grupo de consumo OCTUBRE 2026', Remittance::SOURCE_APP, null);
        $line = $this->withId(RemittanceLine::forPayer($payer, '10.00', 'GC 202610 CUOTA MENSUAL 200'));
        $remittance->addLine($line);
        $remittance->markSent(null, new \DateTimeImmutable('2026-11-05'));

        return $line;
    }

    private function withId(RemittanceLine $line): RemittanceLine
    {
        (new \ReflectionProperty(RemittanceLine::class, 'id'))->setValue($line, $this->nextId++);

        return $line;
    }

    /** Ingreso por ese importe, ese día, en una cuenta de ese tipo. */
    private static function income(string $amount, string $day, int $kind): AccountEntry
    {
        return (new AccountEntry())
            ->setAmount($amount)
            ->setDate(new \DateTimeImmutable($day))
            ->setAccount((new FinancialAccount())->setKind($kind));
    }

    /** @return callable(Remittance): null */
    private static function noCredit(): callable
    {
        return static fn (): ?\DateTimeImmutable => null;
    }

    /** @return callable(Remittance): \DateTimeImmutable */
    private static function credited(): callable
    {
        return static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::CREDIT_DAY);
    }
}
