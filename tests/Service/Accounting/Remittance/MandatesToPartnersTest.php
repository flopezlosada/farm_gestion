<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\MandatesToPartners;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Pasar los mandatos y las cuentas de una remesa enviada a las fichas: sólo se
 * rellena lo que falta, lo que no coincide se cuenta y no se toca. Los casos salen
 * de la remesa real de agosto: una persona con dos recibos, cuentas que han cambiado
 * y una cuenta con un punto al final. El validador es el de verdad: el dígito de
 * control de cada IBAN es lo que se está probando.
 */
class MandatesToPartnersTest extends TestCase
{
    private const IBAN_A = 'ES9121000418450200051332';
    private const IBAN_B = 'ES7921000813610123456789';
    /** La A con una cifra cambiada: lo que deja una errata al copiarla a mano. */
    private const IBAN_A_TYPO = 'ES9121000418450200051333';
    /** Otra cuenta válida que, enmascarada, sale igual que la A: «ES91 … 1332». */
    private const IBAN_A_LOOKALIKE = 'ES9121000418450209751332';

    private function service(): MandatesToPartners
    {
        return new MandatesToPartners(Validation::createValidator());
    }

    private function partner(string $name): Partner
    {
        return (new Partner())->setname($name);
    }

    private function line(?Partner $partner, string $mandate, string $iban = self::IBAN_A, ?string $signedOn = '2022-11-01'): RemittanceLine
    {
        return (new RemittanceLine())
            ->setPartner($partner)->setDebtorName('X')->setIban($iban)
            ->setMandateReference($mandate)->setMandateSignedOn($signedOn !== null ? new \DateTimeImmutable($signedOn) : null)
            ->setDebitReference($mandate . ' 202608')->setSequenceType('RCUR')->setAmount('55.00');
    }

    /** @param list<RemittanceLine> $lines */
    private function sent(array $lines): Remittance
    {
        return Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-08-01'), 'Recibos AGOSTO 2026', 'agosto.xlsx', $lines, null);
    }

    public function testRellenaLaFichaVaciaYNoTocaLaQueYaCoincide(): void
    {
        $empty = $this->partner('Ana');
        $done = $this->partner('Bea')->setIban(self::IBAN_B)->setMandateReference('CUOTA MENSUAL 201')->setMandateSignedOn(new \DateTimeImmutable('2021-01-01'));
        $remittance = $this->sent([$this->line($empty, 'CUOTA MENSUAL 200'), $this->line($done, 'cuota mensual 201', self::IBAN_B)]);
        $service = $this->service();

        $plan = $service->plan($remittance, [$done]);
        $this->assertCount(1, $plan['mandates']);
        $this->assertCount(1, $plan['ibans']);
        $this->assertSame([], $plan['differences'], 'El mismo mandato escrito en minúsculas no es una diferencia.');
        $this->assertNull($empty->getMandateReference(), 'Planear no cambia ninguna ficha.');

        $service->apply($remittance, $plan);
        $this->assertSame('CUOTA MENSUAL 200', $empty->getMandateReference());
        $this->assertSame('2022-11-01', $empty->getMandateSignedOn()->format('Y-m-d'));
        $this->assertSame(self::IBAN_A, $empty->getIban());
        $this->assertSame('2021-01-01', $done->getMandateSignedOn()->format('Y-m-d'), 'Lo que ya tenía se queda.');
    }

    /** Lo que no coincide se enseña y no se pisa: la ficha puede ser lo corregido después. */
    public function testLoQueNoCoincideNoSePisa(): void
    {
        $otherMandate = $this->partner('Ana')->setMandateReference('CUOTA MENSUAL 300')->setMandateSignedOn(new \DateTimeImmutable('2020-01-01'));
        $otherIban = $this->partner('Bea')->setIban(self::IBAN_B);
        $holder = $this->partner('Carla')->setIban(self::IBAN_A)->setMandateReference('CUOTA MENSUAL 302')->setMandateSignedOn(new \DateTimeImmutable('2020-01-01'));
        $wantsTaken = $this->partner('Dani');
        $remittance = $this->sent([
            $this->line($otherMandate, 'CUOTA MENSUAL 301'),
            $this->line($otherIban, 'CUOTA MENSUAL 303', self::IBAN_A),
            $this->line($wantsTaken, 'CUOTA MENSUAL 302'),
            $this->line(null, 'CUOTA MENSUAL 304'),
        ]);
        $service = $this->service();

        $plan = $service->plan($remittance, [$otherMandate, $holder]);
        $service->apply($remittance, $plan);

        $this->assertCount(3, $plan['differences']);
        $this->assertStringContainsString('«CUOTA MENSUAL 300»', $plan['differences'][0]['text']);
        $this->assertStringContainsString('La cuenta de la ficha (ES79 … 6789)', $plan['differences'][1]['text']);
        $this->assertSame($otherIban, $plan['differences'][1]['partner'], 'Cada diferencia dice de quién es: la pantalla enlaza a su ficha.');
        $this->assertStringContainsString('ya lo tiene Carla', $plan['differences'][2]['text']);
        $this->assertSame(1, $plan['unlinked']);
        $this->assertSame('CUOTA MENSUAL 300', $otherMandate->getMandateReference());
        $this->assertSame(self::IBAN_B, $otherIban->getIban());
        $this->assertNull($wantsTaken->getMandateReference(), 'Un mandato de otrx no se repite.');
        $this->assertSame(self::IBAN_A, $wantsTaken->getIban(), 'La cuenta sí se le pone: eso no lo impide.');
    }

    /**
     * Una cuenta de ficha que ni es un IBAN válido es una errata: se corrige con la
     * del recibo, con la que el banco cobró. Si la del recibo tampoco vale, no.
     */
    public function testLaCuentaMalEscritaDeLaFichaSeCorrige(): void
    {
        $typo = $this->partner('Ana')->setIban(self::IBAN_A_TYPO);
        $bothWrong = $this->partner('Bea')->setIban(self::IBAN_A_TYPO);
        $remittance = $this->sent([
            $this->line($typo, 'CUOTA MENSUAL 200'),
            $this->line($bothWrong, 'CUOTA MENSUAL 201', 'ES7921000813610123456780'),
        ]);
        $service = $this->service();

        $plan = $service->plan($remittance, []);
        $this->assertCount(1, $plan['repairs']);
        $this->assertSame($typo, $plan['repairs'][0]['partner']);
        $this->assertSame([], $plan['ibans'], 'Corregir no es rellenar un hueco.');
        $this->assertCount(1, $plan['differences']);
        $this->assertSame($bothWrong, $plan['differences'][0]['partner']);
        $this->assertSame(self::IBAN_A_TYPO, $typo->getIban(), 'Planear no cambia ninguna ficha.');

        $service->apply($remittance, $plan);
        $this->assertSame(self::IBAN_A, $typo->getIban());
        $this->assertSame(self::IBAN_A_TYPO, $bothWrong->getIban());
    }

    /** Dos cuentas válidas que enmascaradas salen iguales: el aviso dice dónde cambian. */
    public function testCuentasQueEnmascaradasSalenIguales(): void
    {
        $partner = $this->partner('Ana')->setIban(self::IBAN_A_LOOKALIKE);
        $plan = $this->service()->plan($this->sent([$this->line($partner, 'CUOTA MENSUAL 200')]), []);

        $this->assertSame([], $plan['repairs'], 'Dos cuentas válidas: decide una persona.');
        $this->assertStringContainsString('(ES91 … 1332) no es la del recibo (ES91 … 1332): cambian cifras del medio', $plan['differences'][0]['text']);
        $this->assertSame(self::IBAN_A_LOOKALIKE, $partner->getIban());
    }

    /** Quien tiene dos recibos se queda con el primer mandato; el segundo se avisa, no gana el último. */
    public function testDosRecibosDeLaMismaPersona(): void
    {
        $partner = $this->partner('Ana');
        $remittance = $this->sent([$this->line($partner, 'CUOTA MENSUAL 200'), $this->line($partner, 'CUOTA MENSUAL 201', self::IBAN_B)]);
        $service = $this->service();

        $plan = $service->plan($remittance, []);
        $service->apply($remittance, $plan);

        $this->assertCount(1, $plan['mandates']);
        $this->assertCount(1, $plan['ibans']);
        $this->assertCount(2, $plan['differences'], 'Ni el segundo mandato ni la segunda cuenta.');
        $this->assertSame('CUOTA MENSUAL 200', $partner->getMandateReference());
        $this->assertSame(self::IBAN_A, $partner->getIban());
    }

    public function testSinFechaDeFirmaNoSePasa(): void
    {
        $partner = $this->partner('Ana');
        $plan = $this->service()->plan($this->sent([$this->line($partner, 'CUOTA MENSUAL 200', signedOn: null)]), []);

        $this->assertSame([], $plan['mandates']);
        $this->assertStringContainsString('no dice cuándo se firmó', $plan['differences'][0]['text']);
    }

    public function testUnBorradorNoSePasaALasFichas(): void
    {
        $draft = new Remittance(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-09-01'), 'Recibos SEPTIEMBRE 2026', Remittance::SOURCE_APP, null);

        $this->expectException(\LogicException::class);
        $this->service()->apply($draft, ['mandates' => [], 'ibans' => [], 'repairs' => []]);
    }

    /** Un IBAN no lleva signos: el punto final que traía un Excel real no es parte de la cuenta. */
    public function testLaCuentaSeGuardaSinSignos(): void
    {
        $this->assertSame(self::IBAN_A, RemittanceLine::normalizeIban(' es91 2100-0418 4502 0005 1332. '));
        $this->assertSame(self::IBAN_A, $this->partner('Ana')->setIban('ES91 2100 0418 4502 0005 1332.')->getIban());
        $this->assertNull($this->partner('Bea')->setIban(' . ')->getIban(), 'Sin cifras no hay cuenta.');
    }
}
