<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerPaymentMethod;
use App\Service\Partner\Fee\FeeCharge;
use App\Service\Partner\Fee\FeeLine;
use App\Service\Partner\Fee\FeeStatement;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo el total de una cuota necesita desglose: con una sola cesta entera de sólo
 * verdura el total es la tarifa y no hay nada que explicar; con huevos o transporte,
 * más cestas, una parte del mes o ajustes, sí.
 */
class FeeStatementTest extends TestCase
{
    /** Una quincenal con una docena y transporte, como la tiene una socia de un nodo. */
    private static function line(Partner $partner, int $weeks = 4): FeeLine
    {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);

        return new FeeLine($share, 5500, 1200, 500, $weeks, 4, []);
    }

    /**
     * Construye el desglose de una persona con las cestas y ajustes dados.
     *
     * @param list<FeeLine>      $lines
     * @param list<FeeAdjustment> $adjustments
     */
    private static function statement(Partner $payer, array $lines, array $adjustments = []): FeeStatement
    {
        $statement = new FeeStatement($payer);
        array_map($statement->addLine(...), $lines);
        array_map($statement->addAdjustment(...), $adjustments);

        return $statement;
    }

    public function testUnaSolaCestaEnteraDeSoloVerduraNoNecesitaDesglose(): void
    {
        $payer = new Partner();
        $share = new PartnerBasketShare();
        $share->setPartner($payer);

        $this->assertFalse(self::statement($payer, [new FeeLine($share, 5500, 0, 0, 4, 4, [])])->needsBreakdown());
    }

    /** Una sola cesta entera con huevos y transporte: el total sólo se entiende partido. */
    public function testUnaSolaCestaConHuevosYTransporteSeDesglosa(): void
    {
        $payer = new Partner();

        $this->assertTrue(self::statement($payer, [self::line($payer)])->needsBreakdown());
    }

    public function testSinCestasNiAjustesNoHayNadaQueDesglosar(): void
    {
        $this->assertFalse(self::statement(new Partner(), [])->needsBreakdown());
    }

    public function testPagarTambienLaCestaDeOtraPersonaSeDesglosa(): void
    {
        $payer = new Partner();

        $this->assertTrue(self::statement($payer, [self::line($payer), self::line(new Partner())])->needsBreakdown());
    }

    public function testUnaCestaDeParteDelMesSeDesglosa(): void
    {
        $payer = new Partner();

        $this->assertTrue(self::statement($payer, [self::line($payer, 2)])->needsBreakdown());
    }

    public function testUnAjusteSeDesglosaAunqueSeaLaUnicaCesta(): void
    {
        $payer = new Partner();
        $more = new FeeAdjustment($payer, '12.00', 'amplía su cuota', new \DateTimeImmutable('2026-11-01'));

        $this->assertTrue(self::statement($payer, [self::line($payer)], [$more])->needsBreakdown());
    }

    /** Quien sólo paga un ajuste (la cesta la tiene otra persona): el ajuste se explica. */
    public function testSoloUnAjusteSinCestaSeDesglosa(): void
    {
        $payer = new Partner();
        $more = new FeeAdjustment($payer, '55.00', 'sigue pagando aunque se fue', new \DateTimeImmutable('2026-11-01'));

        $this->assertTrue(self::statement($payer, [], [$more])->needsBreakdown());
    }

    /**
     * La cuota de socix suma al total y se explica aunque haya una sola cesta; sin
     * precio no se puede cobrar y dice qué falta.
     */
    public function testLaCuotaDeSocixSumaSeDesglosaYSinPrecioNoSeCobra(): void
    {
        $payer = new Partner();
        $statement = self::statement($payer, [self::line($payer)]);
        $statement->addCharge(new FeeCharge(FeeConcept::MembershipSignup, $payer, 2500));

        $this->assertSame('97.00', $statement->getTotal(), '55 + 12 + 5 de la cesta y 25 de inscripción.');
        $this->assertSame(2500, $statement->getChargesCents());
        $this->assertTrue($statement->needsBreakdown());
        $this->assertSame([], $statement->getMissingConcepts());

        $statement->addCharge(new FeeCharge(FeeConcept::MembershipUpkeep, $payer, null));
        $this->assertSame([FeeConcept::MembershipUpkeep], $statement->getMissingConcepts());
        $this->assertStringContainsString('cuota de mantenimiento', $statement->getProblems()[0]);
    }

    /**
     * Quien paga por transferencia no necesita mandato, pero sí precios: tiene cuota y
     * hay que saber cuánto transfiere. No va en la remesa. Quien no paga no tiene nada
     * que arreglar.
     */
    public function testSinReciboNoSePideMandatoNiEntraEnLaRemesa(): void
    {
        $receipt = new Partner();
        $transfer = (new Partner())->setPaymentMethod(PartnerPaymentMethod::Transfer);
        $none = (new Partner())->setPaymentMethod(PartnerPaymentMethod::NotCharged)->setPaymentNote('Cobra el transporte en cestas');

        $this->assertSame(['No tiene mandato de domiciliación completo (cuenta, referencia y fecha).'], self::statement($receipt, [self::line($receipt)])->getProblems());

        $byTransfer = self::statement($transfer, [self::line($transfer)]);
        $this->assertSame([], $byTransfer->getProblems());
        $this->assertFalse($byTransfer->isChargeable());
        $byTransfer->addCharge(new FeeCharge(FeeConcept::MembershipUpkeep, $transfer, null));
        $this->assertStringContainsString('cuota de mantenimiento', $byTransfer->getProblems()[0], 'Sin precio no se sabe cuánto transfiere.');

        $notCharged = self::statement($none, [self::line($none)]);
        $notCharged->addCharge(new FeeCharge(FeeConcept::MembershipUpkeep, $none, null));
        $this->assertSame([], $notCharged->getProblems());
        $this->assertFalse($notCharged->isChargeable());
        $this->assertSame('72.00', $notCharged->getTotal(), 'La cuota sigue siendo lo que vale su cesta.');
    }
}
