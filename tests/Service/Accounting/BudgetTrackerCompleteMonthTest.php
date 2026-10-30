<?php

namespace App\Tests\Service\Accounting;

use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\BudgetLineRepository;
use App\Service\Accounting\BudgetTracker;
use PHPUnit\Framework\TestCase;

/**
 * Hasta qué mes se compara con el presupuesto ({@see BudgetTracker::lastCompleteMonth}).
 * Un mes a medio anotar tiene las cuotas, que se cobran a primeros, y no las nóminas,
 * que salen a final de mes: contarlo entero hacía que el año pareciera ocho mil euros
 * mejor de lo que iba.
 */
class BudgetTrackerCompleteMonthTest extends TestCase
{
    /** Con el libro a mitad de septiembre, el último mes completo es agosto. */
    public function testAMitadDeMesCuentaElAnterior(): void
    {
        $this->assertSame(8, $this->tracker()->lastCompleteMonth(2026, new \DateTimeImmutable('2026-09-08')));
    }

    /** Con el libro hasta el último día del mes, ese mes ya está completo. */
    public function testElUltimoDiaDelMesLoCompleta(): void
    {
        $this->assertSame(8, $this->tracker()->lastCompleteMonth(2026, new \DateTimeImmutable('2026-08-31')));
        $this->assertSame(2, $this->tracker()->lastCompleteMonth(2028, new \DateTimeImmutable('2028-02-29')), 'Febrero de un año bisiesto acaba el 29.');
        $this->assertSame(2, $this->tracker()->lastCompleteMonth(2026, new \DateTimeImmutable('2026-02-28')), 'En un año que no es bisiesto, febrero acaba el 28.');
        $this->assertSame(1, $this->tracker()->lastCompleteMonth(2028, new \DateTimeImmutable('2028-02-28')), 'En uno bisiesto, al 28 aún le falta un día.');
    }

    /** A mitad de enero todavía no hay ningún mes completo con que comparar. */
    public function testAMitadDeEneroNoHayNingunMes(): void
    {
        $this->assertSame(0, $this->tracker()->lastCompleteMonth(2026, new \DateTimeImmutable('2026-01-20')));
    }

    /**
     * Un año ya pasado, con el libro en el siguiente, está entero; uno al que el libro
     * aún no ha llegado no tiene nada.
     */
    public function testOtroAnio(): void
    {
        $this->assertSame(12, $this->tracker()->lastCompleteMonth(2025, new \DateTimeImmutable('2026-03-10')));
        $this->assertSame(0, $this->tracker()->lastCompleteMonth(2027, new \DateTimeImmutable('2026-03-10')));
    }

    /** Sin ningún apunte de banco (sólo cajas) vale el último mes con apuntes. */
    public function testSinBancosValeElUltimoMesConApuntes(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('totalsByCategoryAndMonth')->willReturn([7 => [3 => '-10.00', 5 => '20.00']]);

        $this->assertSame(5, $this->tracker($entries)->lastCompleteMonth(2026, null));
    }

    private function tracker(?AccountEntryRepository $entries = null): BudgetTracker
    {
        return new BudgetTracker(
            $entries ?? $this->createMock(AccountEntryRepository::class),
            $this->createMock(BudgetLineRepository::class),
            $this->createMock(BudgetCategoryGroupRepository::class),
        );
    }
}
