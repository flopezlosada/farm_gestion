<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\PartnerPaymentMethod;
use App\Entity\PartnerBasketShare;
use App\Entity\Remittance;
use App\Entity\FeeConcept;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\RemittancePartnerMatcher;
use App\Service\Accounting\Remittance\RemittanceReview;
use App\Service\Partner\Fee\FeeLine;
use App\Service\Partner\Fee\FeeStatement;
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

    /** Una cesta de una persona, con sus fechas si se dan. */
    private static function share(Partner $owner, ?string $start = null, ?string $end = null): PartnerBasketShare
    {
        $share = new PartnerBasketShare();
        $share->setPartner($owner);
        $share->setStartDate($start !== null ? new \DateTime($start) : null);
        $share->setEndDate($end !== null ? new \DateTime($end) : null);

        return $share;
    }

    /** La cuota calculada de un mes con una sola cesta, ésa. */
    private static function feeOf(Partner $head, PartnerBasketShare $share, int $cents): FeeStatement
    {
        $statement = new FeeStatement($head);
        $statement->addLine(new FeeLine($share, $cents, 0, 0, 4, 4, []));

        return $statement;
    }

    /**
     * La cuota calculada de quien encabeza la familia: una cesta del mes entero que
     * cuesta lo dicho, y los conceptos a los que les falta precio.
     *
     * @param list<FeeConcept> $missing
     */
    private static function fee(Partner $head, int $cents, array $missing = []): FeeStatement
    {
        $statement = new FeeStatement($head);
        $statement->addLine(new FeeLine(new PartnerBasketShare(), $cents, 0, 0, 4, 4, $missing));

        return $statement;
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
        $this->assertSame(['Su cuota calculada es 65,00 € al mes y en esta remesa se le cobran 70,00 €.'], $warnings[4]);
        $this->assertSame(['Sin socix: no se puede contrastar con sus cestas.'], $warnings[5]);
    }

    /**
     * Si a la cuota le falta un precio la cifra sale corta: no se compara con lo que se
     * cobra, se dice qué precio falta.
     */
    public function testSinPrecioNoSeContrastaElImporte(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $remittance = self::draft([self::line($ana, 'ANA LOPEZ', '55.00', 'CUOTA MENSUAL 1')]);

        $warnings = (new RemittanceReview())->lineWarnings($remittance, [1 => self::fee($ana, 0, [FeeConcept::VegBiweekly])]);

        $this->assertSame(['Falta el precio de cesta quincenal: no se puede contrastar su cuota.'], $warnings[1]);
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
        $remittance->markSent(null, new \DateTimeImmutable('2026-09-18'));

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
     * Cada fila dice por qué no va en la remesa: lo que le falta para cobrarle, y si no
     * le falta nada, que falta su recibo. Sin mandato, el partner de prueba no lo tiene.
     */
    public function testCadaFamiliaSinReciboDiceElMotivo(): void
    {
        $sinMandato = self::partner(1, 'ANA', 'LOPEZ');
        $conMandato = self::partner(2, 'BEA', 'RUIZ');
        $conMandato->setIban('ES9121000418450200051332')->setMandateReference('M-2')->setMandateSignedOn(new \DateTimeImmutable('2024-01-10'));
        $sinPrecio = self::partner(3, 'CARMEN', 'ALONSO');
        $sinPrecio->setIban('ES9121000418450200051332')->setMandateReference('M-3')->setMandateSignedOn(new \DateTimeImmutable('2024-01-10'));
        $expected = [
            1 => self::fee($sinMandato, 6500),
            2 => self::fee($conMandato, 4350),
            3 => self::fee($sinPrecio, 3000, [FeeConcept::VegBiweekly]),
        ];

        $rows = (new RemittanceReview())->familiesWithoutLine(self::draft([]), $expected);

        $this->assertSame([
            'Ana Lopez' => ['No tiene mandato de domiciliación completo (cuenta, referencia y fecha).'],
            'Bea Ruiz' => [RemittanceReview::NOTHING_MISSING],
            'Carmen Alonso' => ['Falta el precio de cesta quincenal.'],
        ], array_combine(
            array_map(static fn (array $r): string => $r['partner']->getLegalName(), $rows),
            array_column($rows, 'reasons'),
        ));
    }

    /**
     * Quien paga por transferencia o no paga no es una familia sin recibo: sale aparte,
     * con cómo paga, y no cuenta como algo que mirar.
     */
    public function testQuienNoPagaPorReciboSaleAparte(): void
    {
        $ana = self::partner(1, 'ANA', 'LOPEZ');
        $david = self::partner(2, 'DAVID', 'SIERRA');
        $david->setPaymentMethod(PartnerPaymentMethod::NotCharged)->setPaymentNote('cobra el transporte en cestas');
        $eva = self::partner(3, 'EVA', 'SANZ');
        $eva->setPaymentMethod(PartnerPaymentMethod::Transfer);
        $expected = [1 => self::fee($ana, 6500), 2 => self::fee($david, 28800), 3 => self::fee($eva, 3000)];
        $review = new RemittanceReview();

        $this->assertSame(['Ana Lopez'], array_map(static fn (array $r): string => $r['partner']->getLegalName(), $review->familiesWithoutLine(self::draft([]), $expected)));
        $this->assertSame([
            ['David Sierra', '288.00', 'No paga: cobra el transporte en cestas.'],
            ['Eva Sanz', '30.00', 'Paga por transferencia.'],
        ], array_map(static fn (array $r): array => [$r['partner']->getLegalName(), $r['amount'], $r['how']], $review->familiesPayingOtherwise(self::draft([]), $expected)));
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
            ['name' => 'Bea Ruiz', 'partner' => $bea, 'before' => '43.50', 'after' => '55.00', 'change' => RemittanceReview::CHANGE_AMOUNT],
            ['name' => 'Carmen Alonso', 'partner' => $carmen, 'before' => '30.00', 'after' => null, 'change' => RemittanceReview::CHANGE_GONE],
            ['name' => 'Dani Gil', 'partner' => $dani, 'before' => null, 'after' => '60.00', 'change' => RemittanceReview::CHANGE_NEW],
        ], $changes);
        $this->assertSame([], (new RemittanceReview())->changesFrom(null, $september));

        // Las sumas: entra 60, sale 30, sube 11,50; la remesa sube 41,50, lo que va de
        // una a otra (173,50 → 215,00).
        $totals = (new RemittanceReview())->changesTotals($changes, $august, $september);
        $this->assertSame(['60.00', '30.00', '11.50', '0.00', '41.50'], [$totals['new'], $totals['gone'], $totals['up'], $totals['down'], $totals['net']]);
        $this->assertTrue($totals['addsUp']);
    }

    /**
     * El porqué de cada cambio sale de la cuota calculada de los dos meses: la cesta que
     * cambia de precio, la que se deja, la que empieza. Si la app no da porqué, o lo
     * calculado no cambia como lo cobrado, no está explicado: en rojo, y diciendo en qué
     * mes lo cobrado no es lo que calcula la app.
     */
    public function testElPorQueDeCadaCambioSaleDeLasCestas(): void
    {
        $bea = self::partner(3, 'BEA', 'RUIZ');
        $carmen = self::partner(4, 'CARMEN', 'ALONSO');
        $dani = self::partner(5, 'DANI', 'GIL');
        $eva = self::partner(6, 'EVA', 'SANZ');
        $beaShare = self::share($bea);
        $carmenShare = self::share($carmen, end: '2026-08-31');
        $daniShare = self::share($dani, start: '2026-09-03');
        $changes = [
            ['name' => 'Bea Ruiz', 'partner' => $bea, 'before' => '43.50', 'after' => '55.00', 'change' => RemittanceReview::CHANGE_AMOUNT],
            ['name' => 'Carmen Alonso', 'partner' => $carmen, 'before' => '30.00', 'after' => null, 'change' => RemittanceReview::CHANGE_GONE],
            ['name' => 'Dani Gil', 'partner' => $dani, 'before' => null, 'after' => '60.00', 'change' => RemittanceReview::CHANGE_NEW],
            ['name' => 'Eva Sanz', 'partner' => $eva, 'before' => '30.00', 'after' => '40.00', 'change' => RemittanceReview::CHANGE_AMOUNT],
            ['name' => 'SIN FICHA', 'partner' => null, 'before' => null, 'after' => '35.00', 'change' => RemittanceReview::CHANGE_NEW],
            // De baja: hoy no se le calcula nada en ningún mes, y basta con eso.
            ['name' => 'Fran Ruiz', 'partner' => self::partner(7, 'FRAN', 'RUIZ', status: Partner::STATUS_BAJA), 'before' => '67.00', 'after' => null, 'change' => RemittanceReview::CHANGE_GONE],
            // En agosto tenía pausa y se le cobró la cuota entera: este mes cuadra, la
            // diferencia viene de agosto.
            ['name' => 'Gema Paz', 'partner' => $gema = self::partner(8, 'GEMA', 'PAZ'), 'before' => '67.00', 'after' => '61.00', 'change' => RemittanceReview::CHANGE_AMOUNT],
            // Tenía cesta en agosto y no se le cobró; entra este mes con lo que toca.
            ['name' => 'Hugo Rey', 'partner' => $hugo = self::partner(9, 'HUGO', 'REY'), 'before' => null, 'after' => '30.00', 'change' => RemittanceReview::CHANGE_NEW],
            // Se le cobra sin que la app le calcule nada.
            ['name' => 'Iris Mora', 'partner' => self::partner(10, 'IRIS', 'MORA'), 'before' => null, 'after' => '35.00', 'change' => RemittanceReview::CHANGE_NEW],
        ];
        $evaShare = self::share($eva);
        $gemaShare = self::share($gema);
        $hugoShare = self::share($hugo);
        $before = [3 => self::feeOf($bea, $beaShare, 4350), 4 => self::feeOf($carmen, $carmenShare, 3000), 6 => self::feeOf($eva, $evaShare, 3000), 8 => self::feeOf($gema, $gemaShare, 1220), 9 => self::feeOf($hugo, $hugoShare, 3000)];
        $now = [3 => self::feeOf($bea, $beaShare, 5500), 5 => self::feeOf($dani, $daniShare, 6000), 6 => self::feeOf($eva, $evaShare, 3000), 8 => self::feeOf($gema, $gemaShare, 6100), 9 => self::feeOf($hugo, $hugoShare, 3000)];

        $rows = (new RemittanceReview())->explainChanges($changes, $before, $now, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-09-01'));

        $this->assertSame([
            [['La cesta pasa de 43,50 € a 55,00 €.'], true],
            [['Deja la cesta el 31/08/2026.'], true],
            [['Empieza la cesta el 03/09/2026.'], true],
            [['En septiembre se le cobran 40,00 €, pero la app calcula 30,00 €.'], false],
            [['Recibo sin socix: la app no sabe de quién es.'], false],
            [['Está de baja.'], true],
            [['En agosto se le cobraron 67,00 €, pero la app calcula 12,20 €.', 'La cesta pasa de 12,20 € a 61,00 €.'], false],
            [['En agosto no se le cobró nada, pero la app calcula 30,00 €.'], false],
            [['En septiembre se le cobran 35,00 €, pero la app no le calcula nada.'], false],
        ], array_map(static fn (array $r): array => [$r['reasons'], $r['explained']], $rows));
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
