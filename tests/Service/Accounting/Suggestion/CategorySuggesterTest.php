<?php

namespace App\Tests\Service\Accounting\Suggestion;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\Accounting\Suggestion\CategorySuggester;
use PHPUnit\Framework\TestCase;

/**
 * La partida que se propone para un movimiento del banco sale de las últimas veces que
 * el banco dijo lo mismo. Los conceptos son los del libro real de Fiare en 2026.
 */
class CategorySuggesterTest extends TestCase
{
    private int $nextId = 1;

    /** Sin cifras, signos, tildes ni mayúsculas: el mismo recibo de cada mes. */
    public function testElConceptoSeComparaSinLoQueCambia(): void
    {
        $this->assertSame(CategorySuggester::key('rcbo.caymuz sl'), CategorySuggester::key('rcbo.caymuz, s.l.'));
        $this->assertSame(CategorySuggester::key('FERRETERIA TORRELAGUNA'), CategorySuggester::key('Ferretería Torrelaguna'));
        $this->assertSame(CategorySuggester::key('rcbo. préstamo0013104153'), CategorySuggester::key('rcbo. préstamo0013104999'));
        $this->assertSame('rcbotgsscotizacionregimengeneral', CategorySuggester::key('rcbo.tgss. cotizacion 001 regimen general'));
        $this->assertSame('', CategorySuggester::key('0013104153 / 2026'));
    }

    public function testProponeLaPartidaDeLasUltimasVeces(): void
    {
        $fiare = $this->account();
        $gestoria = $this->category('Gestoría');
        $history = [
            $this->entry($fiare, '2026-09-05', '-170.00', 'rcbo.caymuz, s.l.', $gestoria),
            $this->entry($fiare, '2026-08-05', '-170.00', 'rcbo.caymuz, s.l.', $gestoria),
            $this->entry($fiare, '2026-07-05', '-170.00', 'rcbo.caymuz sl', $gestoria),
        ];

        $suggestion = CategorySuggester::suggest($this->line($fiare, '2026-10-05', -17000, 'RCBO.CAYMUZ, S.L.'), $history);

        $this->assertSame($gestoria, $suggestion?->category);
        $this->assertSame(3, $suggestion->times);
    }

    /** Si las últimas veces no coinciden, mejor nada que una partida que se acepta sin mirar. */
    public function testSiNoCoincidenNoPropone(): void
    {
        $fiare = $this->account();
        $history = [
            $this->entry($fiare, '2026-09-10', '-20.00', 'FERRETERIA TORRELAGUNA', $this->category('Herramientas')),
            $this->entry($fiare, '2026-08-10', '-15.50', 'FERRETERIA TORRELAGUNA', $this->category('Riego')),
        ];

        $this->assertNull(CategorySuggester::suggest($this->line($fiare, '2026-10-10', -300, 'FERRETERIA TORRELAGUNA'), $history));
    }

    /** Sólo cuentan las de la misma cuenta, el mismo sentido, de antes del movimiento y con partida en uso. */
    public function testSoloCuentaLoComparable(): void
    {
        $fiare = $this->account();
        $caixa = $this->account();
        $loan = $this->category('Préstamo');
        $retired = $this->category('Vieja')->setActive(false);
        $line = $this->line($fiare, '2026-10-01', -19789, 'rcbo. préstamo0013104153');

        $this->assertNull(CategorySuggester::suggest($line, [$this->entry($caixa, '2026-09-01', '-197.89', 'rcbo. préstamo0013104153', $loan)]), 'Otra cuenta.');
        $this->assertNull(CategorySuggester::suggest($line, [$this->entry($fiare, '2026-09-01', '197.89', 'rcbo. préstamo0013104153', $loan)]), 'Un cobro no dice la partida de un pago.');
        $this->assertNull(CategorySuggester::suggest($line, [$this->entry($fiare, '2026-11-01', '-197.89', 'rcbo. préstamo0013104153', $loan)]), 'Lo de después no cuenta.');
        $this->assertNull(CategorySuggester::suggest($line, [$this->entry($fiare, '2026-09-01', '-197.89', 'rcbo. préstamo0013104153', $retired)]), 'Partida retirada.');
        $this->assertSame($loan, CategorySuggester::suggest($line, [$this->entry($fiare, '2026-09-01', '-197.89', 'rcbo. préstamo0013104153', $loan)])?->category);
    }

    /** Sólo las últimas cinco: un cambio de partida antiguo no la bloquea para siempre. */
    public function testSoloMiraLasUltimasCinco(): void
    {
        $fiare = $this->account();
        $phone = $this->category('Teléfono');
        $history = array_map(fn (int $m): AccountEntry => $this->entry($fiare, sprintf('2026-%02d-15', $m), '-19.90', 'rcbo.telefonica de espana, s. a. u.', $phone), [9, 8, 7, 6, 5]);
        $history[] = $this->entry($fiare, '2026-04-15', '-19.90', 'rcbo.telefonica de espana, s. a. u.', $this->category('Otros'));

        $suggestion = CategorySuggester::suggest($this->line($fiare, '2026-10-15', -1990, 'rcbo.telefonica de espana, s. a. u.'), $history);

        $this->assertSame($phone, $suggestion?->category);
        $this->assertSame(CategorySuggester::SAMPLE, $suggestion->times);
    }

    /** Varios movimientos, una sola consulta; los que no tienen propuesta no salen. */
    public function testVariosMovimientosUnaSolaConsulta(): void
    {
        $fiare = $this->account();
        $gestoria = $this->category('Gestoría');
        $known = $this->line($fiare, '2026-10-05', -17000, 'rcbo.caymuz, s.l.');
        $unknown = $this->line($fiare, '2026-10-06', -5000, 'transferencia nueva');
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->once())->method('findCategorizedSince')
            ->with([$fiare], new \DateTimeImmutable('2025-04-05'))
            ->willReturn([$this->entry($fiare, '2026-09-05', '-170.00', 'rcbo.caymuz, s.l.', $gestoria)]);

        $suggestions = (new CategorySuggester($entries))->forLines([$unknown, $known]);

        $this->assertSame([$known->getId()], array_keys($suggestions));
        $this->assertSame($gestoria, $suggestions[$known->getId()]->category);
    }

    private function account(): FinancialAccount
    {
        $account = (new FinancialAccount())->setName('Banco '.$this->nextId)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));
        $this->setId($account);

        return $account;
    }

    private function category(string $name): BudgetCategory
    {
        $category = (new BudgetCategory())->setName($name);
        $this->setId($category);

        return $category;
    }

    private function line(FinancialAccount $account, string $date, int $cents, string $text): BankStatementLine
    {
        $day = new \DateTimeImmutable($date);
        $line = new BankStatementLine($account, new Norma43Movement($day, $day, '99', '000', $cents, sprintf('%010d', $this->nextId), '', '', [$text]), 1);
        $this->setId($line);

        return $line;
    }

    private function entry(FinancialAccount $account, string $date, string $amount, string $concept, BudgetCategory $category): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setDate(new \DateTimeImmutable($date))->setAmount($amount)
            ->setConcept($concept)->setCategory($category);
        $this->setId($entry);

        return $entry;
    }

    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
    }
}
