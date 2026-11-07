<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Norma43\BankLineMatcher;
use App\Service\Accounting\Norma43\Norma43Movement;
use PHPUnit\Framework\TestCase;

/**
 * El emparejado de movimientos del banco con apuntes, sin base de datos: importe exacto
 * con signo, misma cuenta, ±3 días y único (o el mismo día con tantos apuntes como
 * movimientos) para ligarse solo; hasta ±7 o con varios candidatos, sólo se propone.
 */
class BankLineMatcherTest extends TestCase
{
    private int $nextId = 1;

    /** A 3 días y único: se liga solo. */
    public function testUnicoATresDiasSeLigaSolo(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);
        $entry = $this->entry($bank, '2026-10-07', '-45.50');

        $this->assertSame([[$line, $entry]], BankLineMatcher::safePairs([$line], [$entry]));
    }

    /** A 5 días: se propone, pero no se liga solo. */
    public function testACincoDiasSoloSePropone(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);
        $entry = $this->entry($bank, '2026-10-15', '-45.50');

        $this->assertSame([], BankLineMatcher::safePairs([$line], [$entry]));
        $this->assertSame([$entry], BankLineMatcher::candidates([$line], [$entry])[0]['candidates']);
    }

    /** A 8 días ya no es candidato. */
    public function testAOchoDiasNoEsCandidato(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);

        $this->assertSame([], BankLineMatcher::candidates([$line], [$this->entry($bank, '2026-10-18', '-45.50')])[0]['candidates']);
    }

    /** Dos candidatos: ninguno se liga solo; se proponen los dos, el más cercano primero. */
    public function testDosCandidatosSeProponenAmbos(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', 2000);
        $far = $this->entry($bank, '2026-10-12', '20.00');
        $near = $this->entry($bank, '2026-10-09', '20.00');

        $this->assertSame([], BankLineMatcher::safePairs([$line], [$far, $near]));
        $this->assertSame([$near, $far], BankLineMatcher::candidates([$line], [$far, $near])[0]['candidates']);
    }

    /** Un céntimo de diferencia, el signo cambiado u otra cuenta: no es el mismo movimiento. */
    public function testImporteDistintoSignoUOtraCuentaNoCasan(): void
    {
        $bank = $this->account();
        $other = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);
        $entries = [
            $this->entry($bank, '2026-10-10', '-45.51'),
            $this->entry($bank, '2026-10-10', '45.50'),
            $this->entry($other, '2026-10-10', '-45.50'),
        ];

        $this->assertSame([], BankLineMatcher::candidates([$line], $entries)[0]['candidates']);
        $this->assertSame([], BankLineMatcher::safePairs([$line], $entries));
    }

    /**
     * Dos cargos iguales en días seguidos y un solo apunte: el apunte es candidato de
     * los dos, así que no se liga a ninguno por casualidad.
     */
    public function testUnApunteQueSirveADosMovimientosNoSeLigaSolo(): void
    {
        $bank = $this->account();
        $first = $this->line($bank, '2026-10-10', -1500);
        $second = $this->line($bank, '2026-10-11', -1500);
        $entry = $this->entry($bank, '2026-10-12', '-15.00');

        $this->assertSame([], BankLineMatcher::safePairs([$first, $second], [$entry]));
    }

    /**
     * Dos de 10 € el mismo día en el banco y dos en el libro, con más de 10 € alrededor:
     * se ligan los del día entre sí, en orden, aunque cada apunte sea candidato de
     * varios movimientos de la semana.
     */
    public function testElMismoDiaConTantosApuntesComoMovimientosSeLigan(): void
    {
        $bank = $this->account();
        $a = $this->line($bank, '2026-01-18', 1000);
        $b = $this->line($bank, '2026-01-18', 1000);
        $other = $this->line($bank, '2026-01-15', 1000);
        $ea = $this->entry($bank, '2026-01-18', '10.00');
        $eb = $this->entry($bank, '2026-01-18', '10.00');
        $eOther = $this->entry($bank, '2026-01-15', '10.00');
        $eLoose = $this->entry($bank, '2026-01-16', '10.00');

        $this->assertSame(
            [[$a, $ea], [$b, $eb], [$other, $eOther]],
            BankLineMatcher::safePairs([$a, $b, $other], [$eb, $eLoose, $eOther, $ea]),
            'los apuntes del día por orden de alta; el suelto del 16 no se usa',
        );
    }

    /** Dos movimientos el mismo día y un solo apunte ese día: no se sabe cuál es, se propone. */
    public function testElMismoDiaConMenosApuntesNoSeLiga(): void
    {
        $bank = $this->account();
        $a = $this->line($bank, '2026-10-10', -1500);
        $b = $this->line($bank, '2026-10-10', -1500);

        $this->assertSame([], BankLineMatcher::safePairs([$a, $b], [$this->entry($bank, '2026-10-10', '-15.00')]));
    }

    /** El grupo del mismo día es de una cuenta: el apunte de otra cuenta no cuenta. */
    public function testElMismoDiaEsPorCuenta(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -1500);
        $elsewhere = $this->entry($this->account(), '2026-10-10', '-15.00');

        $this->assertSame([], BankLineMatcher::safePairs([$line], [$elsewhere]));
    }

    /** Con dos movimientos y dos apuntes cada uno a su fecha y lejos del otro, se ligan los dos. */
    public function testDosParejasClarasSeLiganLasDos(): void
    {
        $bank = $this->account();
        $a = $this->line($bank, '2026-10-01', -1500);
        $b = $this->line($bank, '2026-10-25', -1500);
        $ea = $this->entry($bank, '2026-10-02', '-15.00');
        $eb = $this->entry($bank, '2026-10-24', '-15.00');

        $this->assertSame([[$a, $ea], [$b, $eb]], BankLineMatcher::safePairs([$a, $b], [$ea, $eb]));
    }

    /** Muchos movimientos, una sola consulta de apuntes (nada de una por movimiento). */
    public function testCargaLosApuntesDeUnaVez(): void
    {
        $bank = $this->account();
        $lines = array_map(fn (int $d): BankStatementLine => $this->line($bank, sprintf('2026-10-%02d', $d), -100 * $d), range(1, 30));
        $entry = $this->entry($bank, '2026-10-05', '-5.00');
        $repo = $this->createMock(AccountEntryRepository::class);
        $repo->expects($this->once())->method('findWithoutBankLine')
            ->with([$bank], new \DateTimeImmutable('2026-09-24'), new \DateTimeImmutable('2026-11-06'), $this->countOf(30))
            ->willReturn([$entry]);

        $linked = (new BankLineMatcher($repo, $this->openPeriods()))->linkSafe($lines);

        $this->assertSame(1, $linked);
        $this->assertSame($entry, $lines[4]->getEntry());
    }

    /**
     * Para la portada se propone el candidato único aunque esté a más días de los que
     * se ligan solos (a 5), y no se propone nada donde haya duda: dos apuntes para un
     * movimiento, o un apunte que podrían ser dos movimientos. No liga nada.
     */
    public function testLasPropuestasSonLosCandidatosUnicosSinDuda(): void
    {
        $bank = $this->account();
        $clear = $this->line($bank, '2026-10-10', -4550);
        $clearEntry = $this->entry($bank, '2026-10-15', '-45.50');
        $twoEntries = $this->line($bank, '2026-10-10', 2000);
        $first = $this->line($bank, '2026-10-20', -1500);
        $second = $this->line($bank, '2026-10-21', -1500);
        $repo = $this->createStub(AccountEntryRepository::class);
        $repo->method('findWithoutBankLine')->willReturn([
            $clearEntry,
            $this->entry($bank, '2026-10-12', '20.00'),
            $this->entry($bank, '2026-10-09', '20.00'),
            $this->entry($bank, '2026-10-22', '-15.00'),
        ]);

        $proposals = (new BankLineMatcher($repo, $this->openPeriods()))->proposalsFor([$clear, $twoEntries, $first, $second]);

        $this->assertSame([[$clear, $clearEntry]], $proposals);
        $this->assertNull($clear->getEntry());
    }

    /** Lo que se comprueba al confirmar a mano: misma cuenta, importe, ventana y sin ligar. */
    public function testEsCandidato(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);

        $this->assertTrue(BankLineMatcher::isCandidate($line, $this->entry($bank, '2026-10-17', '-45.50')));
        $this->assertFalse(BankLineMatcher::isCandidate($line, $this->entry($bank, '2026-10-18', '-45.50')));
        $this->assertFalse(BankLineMatcher::isCandidate($line, $this->entry($this->account(), '2026-10-10', '-45.50')));
        $line->linkTo($this->entry($bank, '2026-10-10', '-45.50'));
        $this->assertFalse(BankLineMatcher::isCandidate($line, $this->entry($bank, '2026-10-10', '-45.50')), 'ya ligado');
    }

    /** Ligar a mano un apunte de otro importe no se puede: el tipo lo impide. */
    public function testNoSeLigaAOtroImporte(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-10', -4550);

        $this->expectException(\DomainException::class);
        $line->linkTo($this->entry($bank, '2026-10-10', '-45.00'));
    }

    /**
     * El cargo del Ayuntamiento partido en alquiler y suministros antes de subir el
     * extracto: el banco trae 158,95 y el apunte se quedó en 108,95. Casa por el
     * movimiento entero y se liga solo.
     */
    public function testUnApuntePartidoCasaPorElMovimientoEntero(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-09', -15895);
        $rent = $this->entry($bank, '2026-10-09', '-158.95');
        $rent->splitOff('50.00', $this->category(), 'Suministros del local');

        $this->assertSame([[$line, $rent]], BankLineMatcher::safePairs([$line], [$rent]));
        $this->assertTrue(BankLineMatcher::isCandidate($line, $rent));
        $line->linkTo($rent);
        $this->assertSame($rent, $line->getEntry());
    }

    /** La parte separada no se liga a nada: su movimiento va con el apunte del que salió. */
    public function testUnaParteNoSeLiga(): void
    {
        $bank = $this->account();
        $line = $this->line($bank, '2026-10-09', -5000);
        $part = $this->entry($bank, '2026-10-09', '-158.95')->splitOff('50.00', $this->category(), 'Suministros del local');

        $this->expectException(\DomainException::class);
        $line->linkTo($part);
    }

    /**
     * La liquidación de la tarjeta (el caso real de julio, abreviado): el banco cobra el
     * día 5 lo que el libro apuntó en compras sueltas con esa fecha. Suman al céntimo:
     * se juntan en un apunte partido y el cargo se liga a él.
     */
    public function testLasComprasDelDiaQueSumanElCargoSeJuntan(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -45325);
        $vet = $this->entry($bank, '2026-07-05', '-326.40');
        $feed = $this->entry($bank, '2026-07-05', '-110.00');
        $shop = $this->entry($bank, '2026-07-05', '-16.85');

        $this->assertSame([[$card, [$vet, $feed, $shop]]], BankLineMatcher::sameDayBundles([$card], [$shop, $vet, $feed]));
    }

    /** Por un céntimo, o con un apunte del día de más, ya no es el cargo: nada se junta. */
    public function testSiNoSumanAlCentimoNoSeJuntan(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -45325);
        $vet = $this->entry($bank, '2026-07-05', '-326.40');

        $this->assertSame([], BankLineMatcher::sameDayBundles([$card], [$vet, $this->entry($bank, '2026-07-05', '-126.84')]));
        $exact = [$vet, $this->entry($bank, '2026-07-05', '-126.85')];
        $this->assertSame([], BankLineMatcher::sameDayBundles([$card], [...$exact, $this->entry($bank, '2026-07-05', '-1.00')]));
    }

    /**
     * Sólo cuentan los apuntes de ese día, esa cuenta y ese sentido: un ingreso del
     * mismo día o una compra del día 4 no entran en la suma.
     */
    public function testSoloCuentaElMismoDiaCuentaYSentido(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -5000);
        $a = $this->entry($bank, '2026-07-05', '-30.00');
        $b = $this->entry($bank, '2026-07-05', '-20.00');
        $noise = [$this->entry($bank, '2026-07-05', '75.00'), $this->entry($bank, '2026-07-04', '-9.00'), $this->entry($this->account(), '2026-07-05', '-1.00')];

        $this->assertSame([[$card, [$a, $b]]], BankLineMatcher::sameDayBundles([$card], [...$noise, $a, $b]));
    }

    /** Un solo apunte no es un grupo: eso es la pareja de siempre. */
    public function testUnSoloApunteNoEsUnGrupo(): void
    {
        $bank = $this->account();

        $this->assertSame([], BankLineMatcher::sameDayBundles([$this->line($bank, '2026-07-05', -5000)], [$this->entry($bank, '2026-07-05', '-50.00')]));
    }

    /** Dos cargos del mismo día que podrían ser esa suma: hay duda, no se junta ninguno. */
    public function testDosCargosQuePodrianSerLaSumaNoSeJuntan(): void
    {
        $bank = $this->account();
        $lines = [$this->line($bank, '2026-07-05', -5000), $this->line($bank, '2026-07-05', -5000)];

        $this->assertSame([], BankLineMatcher::sameDayBundles($lines, [$this->entry($bank, '2026-07-05', '-30.00'), $this->entry($bank, '2026-07-05', '-20.00')]));
    }

    /** Un traspaso del día no se junta con las compras (su pareja dejaría de casar). */
    public function testUnTraspasoNoEntraEnLaSuma(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -5000);
        $transfer = $this->entry($bank, '2026-07-05', '-100.00')->setCategory($this->transferCategory());
        $a = $this->entry($bank, '2026-07-05', '-30.00');
        $b = $this->entry($bank, '2026-07-05', '-20.00');

        $this->assertSame([[$card, [$a, $b]]], BankLineMatcher::sameDayBundles([$card], [$transfer, $a, $b]));
    }

    /**
     * Al ligar, primero las parejas de siempre; con lo que queda, los grupos. El cargo
     * queda ligado al primer apunte, y los demás pasan a ser partes suyas.
     */
    public function testLigarJuntaElGrupoYLoLiga(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -5000);
        $a = $this->entry($bank, '2026-07-05', '-30.00');
        $b = $this->entry($bank, '2026-07-05', '-20.00');
        $repo = $this->createStub(AccountEntryRepository::class);
        $repo->method('findWithoutBankLine')->willReturn([]);
        $repo->method('findWithoutBankLineOn')->willReturn([$a, $b]);

        $linked = (new BankLineMatcher($repo, $this->openPeriods()))->linkSafe([$card]);

        $this->assertSame(1, $linked);
        $this->assertSame($a, $card->getEntry());
        $this->assertSame($a, $b->getSplitFrom());
        $this->assertSame('-50.00', $a->getMovementAmount());
    }

    /** En un trimestre entregado a la gestoría no se toca nada: ni se junta ni se liga. */
    public function testEnUnTrimestreEntregadoNoSeJunta(): void
    {
        $bank = $this->account();
        $card = $this->line($bank, '2026-07-05', -5000);
        $a = $this->entry($bank, '2026-07-05', '-30.00');
        $b = $this->entry($bank, '2026-07-05', '-20.00');
        $repo = $this->createStub(AccountEntryRepository::class);
        $repo->method('findWithoutBankLine')->willReturn([]);
        $repo->method('findWithoutBankLineOn')->willReturn([$a, $b]);
        $closed = $this->createStub(ClosedPeriods::class);
        $closed->method('closingDeliveryFor')->willReturn(new AccountantDelivery(InvoiceQuarter::of(2026, 3), null, new \DateTimeImmutable('2026-10-04')));

        $this->assertSame(0, (new BankLineMatcher($repo, $closed))->linkSafe([$card]));
        $this->assertNull($card->getEntry());
        $this->assertNull($b->getSplitFrom());
    }

    private function openPeriods(): ClosedPeriods
    {
        $closed = $this->createStub(ClosedPeriods::class);
        $closed->method('closingDeliveryFor')->willReturn(null);

        return $closed;
    }

    private function transferCategory(): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('TRASPASOS')->setKind(BudgetCategoryGroup::KIND_TRANSFER);

        return (new BudgetCategory())->setGroup($group)->setName('Traspaso entre cuentas');
    }

    private function category(): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('ADMINISTRACIÓN')->setKind(BudgetCategoryGroup::KIND_EXPENSE);

        return (new BudgetCategory())->setGroup($group)->setName('Local');
    }

    private function account(): FinancialAccount
    {
        $account = (new FinancialAccount())->setName('Banco '.$this->nextId)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));
        $this->setId($account);

        return $account;
    }

    private function line(FinancialAccount $account, string $date, int $cents): BankStatementLine
    {
        $day = new \DateTimeImmutable($date);

        return new BankStatementLine($account, new Norma43Movement($day, $day, '99', '000', $cents, sprintf('%010d', $this->nextId++), '', '', []), 1);
    }

    private function entry(FinancialAccount $account, string $date, string $amount): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setDate(new \DateTimeImmutable($date))->setAmount($amount)->setConcept('Prueba');
        $this->setId($entry);

        return $entry;
    }

    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
    }
}
