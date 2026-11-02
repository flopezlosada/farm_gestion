<?php

namespace App\Tests\Service\Accounting\Watch;

use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;
use App\Service\Accounting\Watch\AccountingWatchPlan;
use PHPUnit\Framework\TestCase;

/**
 * Qué se avisa y con qué fecha se apunta: lo urgente por tramos de 3 días, el resto
 * por semanas. Las fechas son las que hacen que el registro de efectos no repita.
 */
class AccountingWatchPlanTest extends TestCase
{
    public function testSeparaLoUrgenteDelResumenSemanal(): void
    {
        $notice = $this->item(AgendaUrgency::Soon, 'Notificación oficial sin revisar (DEHú)');
        $invoices = $this->item(AgendaUrgency::Normal, 'Revisar 3 facturas de la bandeja');
        $delivery = $this->item(AgendaUrgency::Soon, 'Mandar el 4T a la gestoría');

        $plan = new AccountingWatchPlan();

        $this->assertSame([$notice, $delivery], $plan->urgent([$notice, $invoices, $delivery]));
        $this->assertSame([$invoices], $plan->weekly([$notice, $invoices, $delivery]));
        $this->assertSame([], $plan->urgent([]));
    }

    /** Tres días seguidos comparten tramo; el cuarto empieza otro. También antes de la fecha de partida. */
    public function testElAvisoUrgenteSeRepiteCadaTresDias(): void
    {
        $plan = new AccountingWatchPlan();
        $window = fn (string $day): string => $plan->urgentWindow(new \DateTimeImmutable($day))->format('Y-m-d');

        $this->assertSame('2026-11-03', $window('2026-11-03'));
        $this->assertSame('2026-11-03', $window('2026-11-04'));
        $this->assertSame('2026-11-03', $window('2026-11-05'));
        $this->assertSame('2026-11-06', $window('2026-11-06'));
        $this->assertSame('2026-01-01', $window('2026-01-01'));
        $this->assertSame('2026-01-01', $window('2026-01-03'));
        $this->assertSame('2025-12-29', $window('2025-12-31'));
        $this->assertSame('2025-12-29', $window('2025-12-29'));
        $this->assertSame('2026-11-03', $window('2026-11-04 17:45'), 'La hora del día no cambia el tramo.');
    }

    /** En diez días (lo que tarda una notificación oficial en darse por notificada) el aviso sale al menos tres veces. */
    public function testUnaNotificacionOficialAvisaTresVecesAntesDeDarsePorNotificada(): void
    {
        $plan = new AccountingWatchPlan();
        $windows = [];
        for ($day = 0; $day < 10; ++$day) {
            $windows[] = $plan->urgentWindow((new \DateTimeImmutable('2026-11-02'))->modify("+$day days"))->format('Y-m-d');
        }

        $this->assertGreaterThanOrEqual(3, \count(array_unique($windows)));
    }

    public function testElResumenSeApuntaConElLunesDeLaSemana(): void
    {
        $plan = new AccountingWatchPlan();

        $this->assertSame('2026-11-02', $plan->week(new \DateTimeImmutable('2026-11-02'))->format('Y-m-d'), 'Lunes.');
        $this->assertSame('2026-11-02', $plan->week(new \DateTimeImmutable('2026-11-08 23:00'))->format('Y-m-d'), 'Domingo.');
        $this->assertSame('2026-11-09', $plan->week(new \DateTimeImmutable('2026-11-09'))->format('Y-m-d'));
    }

    /** La huella cambia si cambia el título (el plazo que se pasa) o el objeto, no si cambia el detalle. */
    public function testLaHuellaDeUnaTarea(): void
    {
        $base = new AgendaItem(AgendaUrgency::Soon, 'El plazo acaba el 5 de enero', ['Faltan 3 días.'], 'accounting_accountant', ['quarter' => '2026-4']);
        $otherDetails = new AgendaItem(AgendaUrgency::Soon, 'El plazo acaba el 5 de enero', ['Falta 1 día.'], 'accounting_accountant', ['quarter' => '2026-4']);
        $overdue = new AgendaItem(AgendaUrgency::Soon, 'Se pasó el plazo del 5 de enero', ['Faltan 3 días.'], 'accounting_accountant', ['quarter' => '2026-4']);
        $otherQuarter = new AgendaItem(AgendaUrgency::Soon, 'El plazo acaba el 5 de enero', ['Faltan 3 días.'], 'accounting_accountant', ['quarter' => '2026-3']);

        $this->assertSame($base->key(), $otherDetails->key());
        $this->assertNotSame($base->key(), $overdue->key());
        $this->assertNotSame($base->key(), $otherQuarter->key());
        $this->assertSame(40, \strlen($base->key()));
    }

    private function item(AgendaUrgency $urgency, string $title): AgendaItem
    {
        return new AgendaItem($urgency, $title, [], 'accounting_index');
    }
}
