<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\BasketShare;
use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\RemittanceFromFees;
use App\Service\Partner\Fee\FeeSchedule;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * El borrador de la remesa de cuotas sale del cálculo: un recibo por cada persona que
 * se puede cobrar, con los datos de cobro de su ficha. Quien no tiene mandato o le
 * sale cero no entra.
 */
class RemittanceFromFeesTest extends TestCase
{
    private static function partner(string $name, ?string $mandate = null): Partner
    {
        $partner = (new Partner())->setname($name)->setSurname('López García');
        if ($mandate !== null) {
            $partner->setIban('ES91 2100 0418 4502 0005 1332')->setMandateReference($mandate)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        }

        return $partner;
    }

    private static function weekly(Partner $partner): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        (new \ReflectionProperty(BasketShare::class, 'id'))->setValue($basketShare, BasketShare::ID_WEEKLY);
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basketShare);
        $share->setAmount(1);
        $share->setIsActive(true);

        return $share;
    }

    public function testUnReciboPorCadaCuotaCobrable(): void
    {
        $ana = self::partner('Ana', 'CUOTA MENSUAL 200');
        $bea = self::partner('Bea');                          // sin mandato: no entra
        $carla = self::partner('Carla', 'CUOTA MENSUAL 201'); // se lo descuenta todo: no entra
        $weeks = array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), ['2026-11-06', '2026-11-13', '2026-11-20', '2026-11-27']);
        $schedule = new FeeSchedule([new FeeRate(FeeConcept::VegWeekly, '100', new \DateTimeImmutable('2026-01-01'), null)]);
        $adjustments = [
            new FeeAdjustment($ana, '15', 'amplía su cuota', new \DateTimeImmutable('2026-11-01')),
            new FeeAdjustment($carla, '-100', 'cesta donada', new \DateTimeImmutable('2026-11-01')),
        ];
        $fees = MonthlyFeeCalculator::compute(new \DateTimeImmutable('2026-11-01'), $weeks, [self::weekly($ana), self::weekly($bea), self::weekly($carla)], $schedule, $adjustments, []);

        $remittance = (new RemittanceFromFees())->build($fees, null);

        $this->assertTrue($remittance->isDraft());
        $this->assertSame(Remittance::KIND_BASKET, $remittance->getKind());
        $this->assertSame('Recibos NOVIEMBRE 2026', $remittance->getLabel());
        $this->assertCount(1, $remittance->getLines());
        /** @var RemittanceLine $line */
        $line = $remittance->getLines()->first();
        $this->assertSame($ana, $line->getPartner());
        $this->assertSame('ANA LÓPEZ GARCÍA', $line->getDebtorName());
        $this->assertSame('ES9121000418450200051332', $line->getIban());
        $this->assertSame('CUOTA MENSUAL 200', $line->getMandateReference());
        $this->assertSame('CUOTA MENSUAL 200 202611', $line->getDebitReference());
        $this->assertSame(RemittanceLine::SEQUENCE_FIRST, $line->getSequenceType(), 'Como se envía hoy: FRST en todos.');
        $this->assertSame('115.00', $line->getAmount());
        $this->assertSame([], $remittance->sendProblems(), 'El borrador sale listo para enviar.');
    }
}
