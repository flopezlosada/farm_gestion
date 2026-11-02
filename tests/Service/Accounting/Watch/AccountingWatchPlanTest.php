<?php

namespace App\Tests\Service\Accounting\Watch;

use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;
use App\Service\Accounting\Watch\AccountingWatchPlan;
use PHPUnit\Framework\TestCase;

/**
 * Qué se avisa y cuándo: lo urgente a los 3 días del último aviso, el resto por
 * semanas.
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

    /** Lo nunca avisado toca ya; lo avisado, a los tres días del último aviso. */
    public function testElAvisoUrgenteSeRepiteATresDiasDelUltimo(): void
    {
        $plan = new AccountingWatchPlan();
        $due = fn (?string $last, string $today): bool => $plan->isUrgentDue(
            null === $last ? null : new \DateTimeImmutable($last),
            new \DateTimeImmutable($today),
        );

        $this->assertTrue($due(null, '2026-11-03'));
        $this->assertFalse($due('2026-11-03', '2026-11-03'), 'El mismo día, no.');
        $this->assertFalse($due('2026-11-03', '2026-11-05'));
        $this->assertTrue($due('2026-11-03', '2026-11-06'));
        $this->assertTrue($due('2026-11-03', '2026-11-20'), 'Si el reloj se paró, en cuanto vuelve.');
        $this->assertTrue($due('2026-11-03 23:50', '2026-11-06 00:10'), 'La hora del aviso anterior no cuenta.');
    }

    /**
     * Lo que aparece un día cualquiera no se repite al día siguiente. Con los tramos
     * fijos de antes, lo aparecido el último día de un tramo volvía a avisar al otro.
     */
    public function testNoRepiteAlDiaSiguienteAparezcaCuandoAparezca(): void
    {
        $plan = new AccountingWatchPlan();
        foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $first) {
            $next = (new \DateTimeImmutable($first))->modify('+1 day');

            $this->assertFalse($plan->isUrgentDue(new \DateTimeImmutable($first), $next), $first);
        }
    }

    /**
     * En diez días (lo que tarda una notificación oficial en darse por notificada) el
     * aviso sale cuatro veces: al llegar (lo da el buzón) y a los 3, 6 y 9 días.
     */
    public function testUnaNotificacionOficialAvisaCuatroVecesAntesDeDarsePorNotificada(): void
    {
        $plan = new AccountingWatchPlan();
        $last = new \DateTimeImmutable('2026-11-02 09:15');
        $alerts = 1;
        for ($day = 1; $day < 10; ++$day) {
            $today = (new \DateTimeImmutable('2026-11-02'))->modify("+$day days");
            if ($plan->isUrgentDue($last, $today)) {
                $last = $today;
                ++$alerts;
            }
        }

        $this->assertSame(4, $alerts);
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
