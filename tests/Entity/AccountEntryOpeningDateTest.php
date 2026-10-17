<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Form\AccountTransferType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Un movimiento no puede ser anterior a la apertura de su cuenta
 * ({@see FinancialAccount::isOpenOn}): el saldo empieza a sumar en la apertura y lo
 * anterior se quedaba fuera sin avisar. En el libro de 2026 dos apuntes de una caja
 * llegaron fechados en 2023 por una errata del Excel y descuadraban su saldo sin
 * que ninguna pantalla lo dijera.
 */
class AccountEntryOpeningDateTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** El mismo día de la apertura ya cuenta; el anterior, no. */
    public function testLaCuentaAdmiteDesdeElDiaDeSuApertura(): void
    {
        $account = $this->account('2026-01-01');

        $this->assertFalse($account->isOpenOn(new \DateTimeImmutable('2025-12-31')));
        $this->assertTrue($account->isOpenOn(new \DateTimeImmutable('2026-01-01 18:30')), 'La hora no cuenta: es el mismo día.');
        $this->assertTrue($account->isOpenOn(new \DateTimeImmutable('2026-08-31')));
    }

    /** La errata real: el año mal tecleado se para en la casilla de la fecha. */
    public function testUnApunteAnteriorALaAperturaSeRechazaEnLaFecha(): void
    {
        $violations = $this->validator->validate($this->entry('2023-01-20'));

        $this->assertCount(1, $violations);
        $this->assertSame('date', $violations[0]->getPropertyPath());
        $this->assertStringContainsString('Caja local empieza el 01/01/2026', (string) $violations[0]->getMessage());
    }

    public function testUnApunteDesdeLaAperturaEsValido(): void
    {
        $this->assertCount(0, $this->validator->validate($this->entry('2026-01-01')));
    }

    /** Un traspaso son dos apuntes: basta con que una de las dos cuentas no estuviera abierta. */
    public function testUnTraspasoAnteriorALaAperturaDeCualquieraDeLasCuentasSeRechaza(): void
    {
        $rule = new Callback([AccountTransferType::class, 'validateDateWithinAccounts']);
        $old = $this->account('2025-01-01', 'Fiare');
        $new = $this->account('2026-01-01');
        $transfer = static fn (string $date): array => ['date' => new \DateTimeImmutable($date), 'from' => $old, 'to' => $new];

        $violations = $this->validator->validate($transfer('2025-06-01'), $rule);
        $this->assertCount(1, $violations, 'Fiare ya estaba abierta; la caja no.');
        $this->assertStringContainsString('Caja local', (string) $violations[0]->getMessage());

        $this->assertCount(0, $this->validator->validate($transfer('2026-03-01'), $rule));
    }

    private function entry(string $date): AccountEntry
    {
        return (new AccountEntry())
            ->setDate(new \DateTimeImmutable($date))
            ->setAccount($this->account('2026-01-01'))
            ->setCategory(new BudgetCategory())
            ->setConcept('Transporte de enero')
            ->setAmount('-50.00');
    }

    private function account(string $opening, string $name = 'Caja local'): FinancialAccount
    {
        return (new FinancialAccount())
            ->setName($name)
            ->setOpeningBalance('500.00')
            ->setOpeningDate(new \DateTimeImmutable($opening));
    }
}
