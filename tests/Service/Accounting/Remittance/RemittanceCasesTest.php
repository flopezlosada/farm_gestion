<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\MandatesToPartners;
use App\Service\Accounting\Remittance\RemittanceCases;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Los recibos de una remesa enviada que no casan con su ficha, uno por caso y con lo
 * que hace falta para proponer el arreglo. Nombres inventados.
 */
class RemittanceCasesTest extends TestCase
{
    private const IBAN_A = 'ES9121000418450200051332';
    private const IBAN_B = 'ES7921000813610123456789';
    private const IBAN_A_TYPO = 'ES9121000418450200051333';

    private static function cases(): RemittanceCases
    {
        return new RemittanceCases(new MandatesToPartners(Validation::createValidator()));
    }

    private static function partner(string $name, string $surname): Partner
    {
        return (new Partner())->setname($name)->setSurname($surname);
    }

    private static function line(?Partner $partner, string $name, string $mandate, string $iban = self::IBAN_A, string $amount = '55.00'): RemittanceLine
    {
        return (new RemittanceLine())
            ->setPartner($partner)->setDebtorName($name)->setIban($iban)
            ->setMandateReference($mandate)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference('CUOTA CSA MES CORRIENTE')->setSequenceType('FRST')->setAmount($amount);
    }

    /** @param list<RemittanceLine> $lines */
    private static function sent(array $lines): Remittance
    {
        return Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-10-01'), 'Recibos OCTUBRE 2026', 'octubre.xlsx', $lines, null);
    }

    /** @param list<array{kind: string}> $open */
    private static function kinds(array $open): array
    {
        return array_column($open, 'kind');
    }

    public function testCadaPegaEsUnCasoConSuTipo(): void
    {
        $fine = self::partner('Eva', 'Sola Gil')->setIban(self::IBAN_A)->setMandateReference('CUOTA MENSUAL 1')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $empty = self::partner('Luis', 'Prieto Mora');
        $typo = self::partner('Rosa', 'Arana Iturriz')->setIban(self::IBAN_A_TYPO)->setMandateReference('CUOTA MENSUAL 3')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $other = self::partner('Pablo', 'Ruiz Sanz')->setIban(self::IBAN_B)->setMandateReference('CUOTA MENSUAL 4')->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $remittance = self::sent([
            self::line($fine, 'EVA SOLA GIL', 'CUOTA MENSUAL 1'),
            self::line($empty, 'LUIS PRIETO MORA', 'CUOTA MENSUAL 2'),
            self::line($typo, 'ROSA ARANA ITURRIZ', 'CUOTA MENSUAL 3'),
            self::line($other, 'PABLO RUIZ SANZ', 'CUOTA MENSUAL 44'),
            self::line(null, 'Marta Rodríguez Fernándes', 'CUOTA MENSUAL 5'),
        ]);
        $marta = self::partner('Marta', 'Rodríguez Fernández');

        $result = self::cases()->build($remittance, [$fine, $typo, $other], [$fine, $empty, $typo, $other, $marta]);

        $this->assertSame([RemittanceCases::KIND_MISSING, RemittanceCases::KIND_BAD_IBAN, RemittanceCases::KIND_OTHER, RemittanceCases::KIND_UNLINKED], self::kinds($result['open']), 'El que casa con su ficha no es un caso.');
        [$missing, $badIban, $otherCase, $unlinked] = $result['open'];

        $this->assertSame(['la cuenta', 'el mandato'], $missing['fills']);
        $this->assertTrue($missing['fixable']);

        $this->assertTrue($badIban['fixable']);
        $this->assertSame(['ES91', '2100', '0418', '4502', '0005', '1333'], array_column($badIban['ibans'], 'a'), 'La de la ficha, de cuatro en cuatro.');
        $this->assertSame([false, false, false, false, false, true], array_column($badIban['ibans'], 'differs'), 'Marca el grupo donde está la errata.');

        $this->assertCount(2, $otherCase['details'], 'Otro mandato y otra cuenta: un solo caso con las dos pegas.');
        $this->assertTrue($otherCase['fixable']);

        $this->assertSame($marta, $unlinked['candidates'][0]['partner']);
        $this->assertNull($unlinked['partner']);
    }

    /** La cuota y el alta de quien entra son un solo caso, y vale para los dos recibos. */
    public function testLosRecibosDeLaMismaPersonaSonUnSoloCaso(): void
    {
        $fee = self::line(null, 'Clara Isabel Norte Ruiz', 'CUOTA MENSUAL 9', amount: '36.00');
        $joining = self::line(null, 'CLARA ISABEL NORTE RUIZ', 'cuota mensual 9', amount: '25.00');
        $stranger = self::line(null, 'Tomás Ibarra Lago', 'CUOTA MENSUAL 10');
        $remittance = self::sent([$fee, $joining, $stranger]);

        $open = self::cases()->build($remittance, [], [])['open'];

        $this->assertCount(2, $open);
        $this->assertSame($fee, $open[0]['line']);
        $this->assertSame(2, $open[0]['siblings']);
        $this->assertSame([$fee, $joining], RemittanceCases::siblings($remittance, $joining));
        $this->assertSame(1, $open[1]['siblings']);
        $this->assertSame([], $open[1]['candidates'], 'Sin ninguna ficha parecida no se propone nadie.');
    }

    public function testLoQueSeDejoComoEstabaVaAparte(): void
    {
        $kept = self::line(null, 'Tomás Ibarra Lago', 'CUOTA MENSUAL 10');
        $kept->setReviewedAt(new \DateTimeImmutable('2026-10-28'));
        $remittance = self::sent([$kept, self::line(null, 'Eva Sola Gil', 'CUOTA MENSUAL 11')]);

        $result = self::cases()->build($remittance, [], []);

        $this->assertCount(1, $result['open']);
        $this->assertSame([$kept], $result['reviewed']);
    }

    /** Enlazar con otra ficha deshace lo revisado: se revisó contra la anterior. */
    public function testCambiarDeFichaVuelveAAbrirElCaso(): void
    {
        $line = self::line(null, 'Tomás Ibarra Lago', 'CUOTA MENSUAL 10');
        self::sent([$line]);
        $line->setReviewedAt(new \DateTimeImmutable('2026-10-28'));

        $line->setPartner(self::partner('Tomás', 'Ibarra Lago'));

        $this->assertNull($line->getReviewedAt());
        $this->assertSame('55.00', $line->getAmount(), 'Enlazar en una remesa enviada no cambia lo cobrado.');
    }
}
