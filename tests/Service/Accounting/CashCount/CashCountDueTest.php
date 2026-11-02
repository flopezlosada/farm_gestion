<?php

namespace App\Tests\Service\Accounting\CashCount;

use App\Command\SendCashCountReminderCommand;
use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use App\Service\Accounting\CashCount\CashCountDue;
use PHPUnit\Framework\TestCase;

/**
 * Qué cajas faltan por contar este mes y qué pide el bot. El arqueo es de fin de
 * mes: un recuento de la última semana del mes anterior ya vale.
 */
class CashCountDueTest extends TestCase
{
    private int $nextId = 1;

    public function testFaltanLasQueNoSeHanContadoDesdeLaUltimaSemanaDelMesAnterior(): void
    {
        $garden = $this->account('Caja de la huerta');
        $local = $this->account('Caja del local');
        $old = $this->account('Caja vieja');
        $latest = [
            $this->count($local, '2026-10-25'),
            $this->count($old, '2026-10-24'),
        ];

        $pending = CashCountDue::pending([$garden, $local, $old], $latest, new \DateTimeImmutable('2026-11-01'));

        $this->assertSame([$garden, $old], $pending);
        $this->assertSame('2026-10-25', CashCountDue::since(new \DateTimeImmutable('2026-11-15 10:00'))->format('Y-m-d'));
    }

    public function testContadaEsteMesNoFalta(): void
    {
        $garden = $this->account('Caja de la huerta');

        $this->assertSame([], CashCountDue::pending([$garden], [$this->count($garden, '2026-11-02')], new \DateTimeImmutable('2026-11-20')));
    }

    public function testElBotDiceQueCajasContarYComoContestar(): void
    {
        $text = SendCashCountReminderCommand::message([$this->account('Caja de la huerta'), $this->account('Caja del local')]);

        $this->assertStringContainsString('cuenta el dinero de Caja de la huerta y Caja del local', $text);
        $this->assertStringContainsString('«caja de la huerta 450,50»', $text);
        $this->assertStringContainsString('Caja Única', SendCashCountReminderCommand::message([$this->account('Caja Única')]));
    }

    private function account(string $name): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_CASH);
        (new \ReflectionProperty(FinancialAccount::class, 'id'))->setValue($account, $this->nextId++);

        return $account;
    }

    private function count(FinancialAccount $account, string $date): CashCount
    {
        return new CashCount($account, new \DateTimeImmutable($date), 0, 0, null, new \DateTimeImmutable($date . ' 20:00'), 'arqueo', null);
    }
}
