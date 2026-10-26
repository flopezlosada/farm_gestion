<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\AccountEntry;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\Remittance\BankReturns;
use PHPUnit\Framework\TestCase;

/**
 * Qué devoluciones del banco son de una remesa y qué recibos pueden ser cada una. Los
 * conceptos y las cifras son los de la remesa de agosto de 2026 en Fiare: el abono
 * `ab.rem.<ref>` con 55 € menos que la remesa, y dos devoluciones al día siguiente.
 */
class BankReturnsTest extends TestCase
{
    private int $nextId = 1;

    public function testLaReferenciaSaleDelConceptoDeFiare(): void
    {
        $this->assertSame('20260016561', BankReturns::reference('ab.rem.20260016561', BankReturns::CREDIT_PREFIX));
        $this->assertSame('20260016561', BankReturns::reference('bel-liq.rem.devol20260016561', BankReturns::RETURN_PREFIX));
        $this->assertSame('20260016561', BankReturns::reference('  AB.REM.20260016561 ', BankReturns::CREDIT_PREFIX));
        $this->assertNull(BankReturns::reference('bel-com.rem.devol20260016561', BankReturns::RETURN_PREFIX), 'La comisión no es una devolución.');
        $this->assertNull(BankReturns::reference('bel-liq.rem.20260016561', BankReturns::RETURN_PREFIX), 'La comisión de la remesa tampoco.');
        $this->assertNull(BankReturns::reference('ab.rem.', BankReturns::CREDIT_PREFIX));
    }

    /**
     * Las devoluciones de los lotes abonados en el mes de la remesa, menos las ya
     * ligadas. La de un lote de otro mes no es de esta remesa.
     */
    public function testSonLasDevolucionesDeLosLotesDelMesSinLigar(): void
    {
        $credits = [$this->entry('2026-08-04', '97.00', 'ab.rem.20260015586'), $this->entry('2026-08-18', '7417.40', 'ab.rem.20260016561')];
        $linked = $this->entry('2026-08-19', '-36.00', 'bel-liq.rem.devol20260016561');
        $open = $this->entry('2026-08-19', '-55.00', 'bel-liq.rem.devol20260016561');
        $july = $this->entry('2026-08-02', '-97.00', 'bel-liq.rem.devol20260014500');

        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('findByConceptPrefix')->willReturnCallback(function (string $prefix, \DateTimeInterface $from, \DateTimeInterface $to) use ($credits, $linked, $open, $july): array {
            $this->assertSame('2026-08-01', $from->format('Y-m-d'));
            $this->assertSame($prefix === BankReturns::CREDIT_PREFIX ? '2026-08-31' : '2026-09-30', $to->format('Y-m-d'));

            return $prefix === BankReturns::CREDIT_PREFIX ? $credits : [$july, $linked, $open];
        });
        $returns = $this->createMock(RemittanceReturnRepository::class);
        $returns->expects($this->once())->method('findLinkedEntryIds')->with([$linked, $open])->willReturn([$linked->getId()]);

        $found = (new BankReturns($entries, $returns))->unlinkedFor($this->remittance([]));

        $this->assertSame([$open], $found);
    }

    public function testSinAbonoEnElMesNoHayNadaQueBuscar(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->once())->method('findByConceptPrefix')->willReturn([]);
        $returns = $this->createMock(RemittanceReturnRepository::class);
        $returns->expects($this->never())->method('findLinkedEntryIds');

        $this->assertSame([], (new BankReturns($entries, $returns))->unlinkedFor($this->remittance([])));
    }

    /** Para una devolución de 55, los recibos de 55 que no estén ya devueltos. */
    public function testLosCandidatosSonLosDeSuImporteSinDevolver(): void
    {
        $ana = $this->line('ZZTEST ANA', '55.00');
        $luis = $this->line('ZZTEST LUIS', '55.00');
        $sol = $this->line('ZZTEST SOL', '36.00');
        $remittance = $this->remittance([$ana, $luis, $sol]);
        $returns = [new RemittanceReturn($luis, null, null)];

        $this->assertSame([$ana], BankReturns::candidatesFor($this->entry('2026-08-19', '-55.00', 'bel-liq.rem.devol1'), $remittance, $returns));
        $this->assertSame([$sol], BankReturns::candidatesFor($this->entry('2026-08-19', '-36.00', 'bel-liq.rem.devol1'), $remittance, $returns));
        $this->assertSame([], BankReturns::candidatesFor($this->entry('2026-08-19', '-100.00', 'bel-liq.rem.devol1'), $remittance, $returns));
        $this->assertSame([$ana, $sol], BankReturns::notReturned($remittance, $returns));
    }

    /** @param list<RemittanceLine> $lines */
    private function remittance(array $lines): Remittance
    {
        return Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-08-01'), 'Recibos AGOSTO 2026', 'agosto.xlsx', $lines, null);
    }

    private function line(string $name, string $amount): RemittanceLine
    {
        $line = (new RemittanceLine())->setDebtorName($name)->setAmount($amount);
        $this->setId($line);

        return $line;
    }

    private function entry(string $date, string $amount, string $concept): AccountEntry
    {
        $entry = (new AccountEntry())->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount);
        $this->setId($entry);

        return $entry;
    }

    /** Un id como el que pondría la base, que es por lo que se cruzan. */
    private function setId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);
    }
}
