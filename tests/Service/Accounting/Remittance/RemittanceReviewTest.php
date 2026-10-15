<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\RemittancePartnerMatcher;
use App\Service\Accounting\Remittance\RemittanceReview;
use PHPUnit\Framework\TestCase;

/**
 * La revisión de una remesa con familias como las reales: la cesta colgada de un
 * miembro y el recibo a nombre de otro, quien tiene dos recibos (cuota normal y
 * adicional), recibos sin socix, socixs de baja, familias que pagan por
 * transferencia y nombres escritos «APELLIDOS NOMBRE».
 */
class RemittanceReviewTest extends TestCase
{
    private static function partner(int $id, string $name, string $surname, ?string $iban = null, ?Partner $parent = null, string $status = Partner::STATUS_ACTIVO): Partner
    {
        $partner = (new Partner())->setname($name);
        $partner->setSurname($surname);
        $partner->setIban($iban);
        $partner->setParent($parent);
        $partner->setStatus($status);
        (new \ReflectionProperty(Partner::class, 'id'))->setValue($partner, $id);

        return $partner;
    }

    private static function line(?Partner $partner, string $name, string $amount, string $mandate, ?string $iban = 'ES9121000418450200051332'): RemittanceLine
    {
        return (new RemittanceLine())
            ->setPartner($partner)->setDebtorName($name)->setIban($iban)
            ->setMandateReference($mandate)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference($mandate . ' 202609')->setSequenceType('RCUR')->setAmount($amount);
    }

    /**
     * @param list<RemittanceLine> $lines
     */
    private static function draft(array $lines, string $kind = Remittance::KIND_BASKET): Remittance
    {
        $remittance = new Remittance($kind, new \DateTimeImmutable('2026-09-01'), 'Recibos SEPTIEMBRE 2026', Remittance::SOURCE_APP, null);
        foreach ($lines as $i => $line) {
            $remittance->addLine($line);
            (new \ReflectionProperty(RemittanceLine::class, 'id'))->setValue($line, $i + 1);
        }

        return $remittance;
    }

    /** @return array{partner: Partner, cents: int} */
    private static function fee(Partner $head, int $cents): array
    {
        return ['partner' => $head, 'cents' => $cents];
    }

    public function testAvisaDeLoQueNoCasaConLasCestas(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ GARCIA');
        $hija = self::partner(2, 'LUCIA', 'MARTIN LOPEZ', null, $ana);
        $luis = self::partner(3, 'LUIS', 'PEREZ', status: Partner::STATUS_BAJA);
        $sol = self::partner(4, 'SOL', 'MARTIN');
        $remittance = self::draft([
            // La cesta cuelga de Ana; paga su hija con dos recibos que suman lo justo.
            self::line($hija, 'MARTIN LOPEZ LUCIA', '50.00', 'CUOTA MENSUAL 200'),
            self::line($hija, 'MARTIN LOPEZ LUCIA', '15.00', 'CUOTA MENSUAL 201'),
            self::line($luis, 'PEREZ LUIS', '60.00', 'CUOTA MENSUAL 202'),
            self::line($sol, 'MARTIN SOL', '70.00', 'CUOTA MENSUAL 203'),
            self::line(null, 'DESCONOCIDA', '35.00', 'CUOTA MENSUAL 204'),
        ]);
        $expected = [1 => self::fee($ana, 6500), 4 => self::fee($sol, 6500)];

        $warnings = (new RemittanceReview())->lineWarnings($remittance, $expected);

        $this->assertSame([], $warnings[1], 'La familia paga lo que suman sus cestas aunque sea en dos recibos y a nombre de la hija.');
        $this->assertSame([], $warnings[2]);
        $this->assertSame(['Está de baja.', 'No tiene ninguna cesta activa este mes.'], $warnings[3]);
        $this->assertSame(['Sus cestas suman 65,00 € al mes y en esta remesa se le cobran 70,00 €.'], $warnings[4]);
        $this->assertSame(['Sin socix: no se puede contrastar con sus cestas.'], $warnings[5]);
    }

    /** La cuota de socix no tiene que ver con las cestas: no se contrasta. */
    public function testLaCuotaDeSocixNoSeContrastaConLasCestas(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $remittance = self::draft([self::line($ana, 'ANA LOPEZ', '25.00', 'CUOTA SOCIO 1')], Remittance::KIND_MEMBERSHIP);

        $this->assertSame([1 => []], (new RemittanceReview())->lineWarnings($remittance, [1 => self::fee($ana, 6500)]));
        $this->assertSame([], (new RemittanceReview())->familiesWithoutLine($remittance, [1 => self::fee($ana, 6500)]));
    }

    public function testUnaRemesaEnviadaNoSeRevisa(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $remittance = self::draft([self::line($ana, 'ANA LOPEZ', '70.00', 'CUOTA MENSUAL 1')]);
        $remittance->markSent(null);

        $this->assertSame([], (new RemittanceReview())->lineWarnings($remittance, [1 => self::fee($ana, 6500)]));
    }

    /** Quien tiene cesta y no recibo sale en la lista, ordenado; la cesta gratis no. */
    public function testFamiliasConCestaYSinRecibo(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $bea = self::partner(2, 'BEA', 'RUIZ');
        $carmen = self::partner(3, 'CARMEN', 'ALONSO');
        $gratis = self::partner(4, 'DANI', 'GIL');
        $remittance = self::draft([self::line($ana, 'ANA LOPEZ', '65.00', 'CUOTA MENSUAL 1')]);
        $expected = [1 => self::fee($ana, 6500), 2 => self::fee($bea, 4350), 3 => self::fee($carmen, 3000), 4 => self::fee($gratis, 0)];

        $rows = (new RemittanceReview())->familiesWithoutLine($remittance, $expected);

        $this->assertSame(['Bea Ruiz', 'Carmen Alonso'], array_map(static fn (array $r): string => $r['partner']->getLegalName(), $rows));
        $this->assertSame(['43.50', '30.00'], array_column($rows, 'amount'));
    }

    /**
     * De un mes a otro: quien entra, quien sale y a quien le cambia el importe, por
     * familia (o por mandato sin socix). Lo que no cambia no aparece.
     */
    public function testCambiosRespectoALaRemesaAnterior(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $hija = self::partner(2, 'LUCIA', 'MARTIN', null, $ana);
        $bea = self::partner(3, 'BEA', 'RUIZ');
        $carmen = self::partner(4, 'CARMEN', 'ALONSO');
        $dani = self::partner(5, 'DANI', 'GIL');

        $august = self::draft([
            self::line($ana, 'ANA LOPEZ', '65.00', 'CUOTA MENSUAL 1'),
            self::line($bea, 'BEA RUIZ', '43.50', 'CUOTA MENSUAL 2'),
            self::line($carmen, 'CARMEN ALONSO', '30.00', 'CUOTA MENSUAL 3'),
            self::line(null, 'SIN FICHA', '35.00', 'CUOTA MENSUAL 9'),
        ]);
        $september = self::draft([
            self::line($hija, 'LUCIA MARTIN', '65.00', 'CUOTA MENSUAL 1'), // misma familia, mismo total
            self::line($bea, 'BEA RUIZ', '55.00', 'CUOTA MENSUAL 2'),
            self::line($dani, 'DANI GIL', '60.00', 'CUOTA MENSUAL 4'),
            self::line(null, 'SIN FICHA', '35.00', 'CUOTA MENSUAL 9'),
        ]);

        $changes = (new RemittanceReview())->changesFrom($august, $september);

        $this->assertSame([
            ['name' => 'Bea Ruiz', 'before' => '43.50', 'after' => '55.00', 'change' => RemittanceReview::CHANGE_AMOUNT],
            ['name' => 'Carmen Alonso', 'before' => '30.00', 'after' => null, 'change' => RemittanceReview::CHANGE_GONE],
            ['name' => 'Dani Gil', 'before' => null, 'after' => '60.00', 'change' => RemittanceReview::CHANGE_NEW],
        ], $changes);
        $this->assertSame([], (new RemittanceReview())->changesFrom(null, $september));
    }

    /**
     * Emparejar recibos importados con socixs: por cuenta primero, por nombre en
     * cualquier orden y sin tildes después, y nunca si hay dos candidatos.
     */
    public function testEmparejaSoloCuandoNoHayDuda(): void
    {
        $ana = self::partner(1, 'Ana', 'López García', 'ES91 2100 0418 4502 0005 1332');
        $hija = self::partner(2, 'Lucía', 'Martín López', 'ES9121000418450200051332', $ana);
        $luis = self::partner(3, 'Luis', 'Pérez Ruiz');
        $gemelo1 = self::partner(4, 'Juan', 'Gil');
        $gemelo2 = self::partner(5, 'Juan', 'Gil');
        $baja = self::partner(6, 'Rosa', 'Vega', 'ES7921000813610123456789', status: Partner::STATUS_BAJA);
        $alta = self::partner(7, 'Pablo', 'Vega', 'ES7921000813610123456789');

        $lines = [
            self::line(null, 'CUALQUIER NOMBRE', '65.00', 'M1', 'ES9121000418450200051332'), // cuenta de una familia: la titular
            self::line(null, 'PEREZ RUIZ, LUIS', '60.00', 'M2', 'ES1000492352082414205416'),  // por nombre, al revés
            self::line(null, 'GIL JUAN', '30.00', 'M3', 'ES1000492352082414205416'),          // dos con ese nombre
            self::line(null, 'VEGA', '30.00', 'M4', 'ES7921000813610123456789'),              // cuenta compartida, una de baja
            self::line(null, 'NADIE', '30.00', 'M5', 'ES1000492352082414205416'),
        ];

        $unmatched = RemittancePartnerMatcher::matchAgainst($lines, [$ana, $hija, $luis, $gemelo1, $gemelo2, $baja, $alta]);

        $this->assertSame([1, 3, null, 7, null], array_map(static fn (RemittanceLine $l): ?int => $l->getPartner()?->getId(), $lines));
        $this->assertSame(2, $unmatched);
    }

    public function testLaClaveDelNombreNoDependeDelOrdenNiDeLasTildes(): void
    {
        $this->assertSame(RemittancePartnerMatcher::nameKey('Ángela Núñez Gil'), RemittancePartnerMatcher::nameKey('NUÑEZ GIL, ANGELA'));
        $this->assertSame('', RemittancePartnerMatcher::nameKey('  '));
    }
}
