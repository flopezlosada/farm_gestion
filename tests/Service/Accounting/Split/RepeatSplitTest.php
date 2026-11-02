<?php

namespace App\Tests\Service\Accounting\Split;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Service\Accounting\ConceptKey;
use App\Service\Accounting\Split\RepeatSplit;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se parte un cargo que se repite: como el último apunte partido de la misma
 * cuenta con el mismo concepto y el mismo total. Las cifras son las del Ayuntamiento
 * en 2026: 158,95 € = 108,95 de alquiler + 50 de suministros.
 */
class RepeatSplitTest extends TestCase
{
    public function testLaClaveDelConceptoSoloMiraLasLetras(): void
    {
        $this->assertSame(ConceptKey::of('trf.ayuntamiento de torremocha de jarama'), ConceptKey::of('trf. Ayuntamiento de Torremocha de Jarama'));
        $this->assertSame(ConceptKey::of('rcbo. préstamo0013104153'), ConceptKey::of('rcbo. prestamo0013104999'));
        $this->assertSame('', ConceptKey::of('2026/10'));
    }

    public function testParteComoLaUltimaVez(): void
    {
        [$bank, $rent, $supplies] = $this->catalog();
        $october = $this->entry($bank, $rent, '-158.95', '2026-10-09', 'trf. ayuntamiento de torremocha de jarama');
        $part = $october->splitOff('50.00', $supplies, 'Suministros del local, acuerdo verbal con el Ayto');
        $this->assertSame($october, $part->getSplitFrom(), 'La parte sabe de qué apunte sale.');
        $november = $this->entry($bank, $rent, '-158.95', '2026-11-09', 'trf.ayuntamiento de torremocha de jarama');

        $template = RepeatSplit::pick($november, [$october], [$part]);
        $this->assertSame($october, $template['previous']);
        $this->assertSame([$part], $template['parts']);

        $new = RepeatSplit::apply($november, $template['parts']);
        $this->assertSame('-108.95', $november->getAmount());
        $this->assertCount(1, $new);
        $this->assertSame('-50.00', $new[0]->getAmount());
        $this->assertSame($supplies, $new[0]->getCategory());
        $this->assertSame($november, $new[0]->getSplitFrom());
        $this->assertSame('Suministros del local, acuerdo verbal con el Ayto', $new[0]->getConcept());
    }

    /** Otro importe u otro concepto no es el mismo cargo; sin partes, tampoco. */
    public function testSoloElMismoCargo(): void
    {
        [$bank, $rent, $supplies] = $this->catalog();
        $october = $this->entry($bank, $rent, '-158.95', '2026-10-09', 'trf. ayuntamiento de torremocha de jarama');
        $part = $october->splitOff('50.00', $supplies, 'Suministros');

        $this->assertNull(RepeatSplit::pick($this->entry($bank, $rent, '-161.20', '2026-11-09', 'trf. ayuntamiento de torremocha de jarama'), [$october], [$part]));
        $this->assertNull(RepeatSplit::pick($this->entry($bank, $rent, '-158.95', '2026-11-09', 'rcbo.caymuz, s.l.'), [$october], [$part]));
        $this->assertNull(RepeatSplit::pick($this->entry($bank, $rent, '-158.95', '2026-11-09', 'trf. ayuntamiento de torremocha de jarama'), [$october], []));
    }

    /** Si el reparto cambió, manda la vez más reciente. */
    public function testMandaLaMasReciente(): void
    {
        [$bank, $rent, $supplies] = $this->catalog();
        $september = $this->entry($bank, $rent, '-158.95', '2026-09-09', 'trf. ayuntamiento');
        $oldPart = $september->splitOff('40.00', $supplies, 'Suministros (antes)');
        $october = $this->entry($bank, $rent, '-158.95', '2026-10-09', 'trf. ayuntamiento');
        $newPart = $october->splitOff('50.00', $supplies, 'Suministros');

        $template = RepeatSplit::pick($this->entry($bank, $rent, '-158.95', '2026-11-09', 'trf. ayuntamiento'), [$october, $september], [$oldPart, $newPart]);

        $this->assertSame([$newPart], $template['parts']);
    }

    /** @return array{0: FinancialAccount, 1: BudgetCategory, 2: BudgetCategory} */
    private function catalog(): array
    {
        return [
            (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK),
            (new BudgetCategory())->setName('Local'),
            (new BudgetCategory())->setName('Suministros'),
        ];
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $amount, string $date, string $concept): AccountEntry
    {
        return (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount);
    }
}
