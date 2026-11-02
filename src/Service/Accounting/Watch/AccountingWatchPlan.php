<?php

namespace App\Service\Accounting\Watch;

use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;

/**
 * Qué se avisa hoy de la portada de contabilidad. Sin estado: la memoria de lo ya
 * avisado la lleva el registro de efectos de la tarea; esta clase sólo decide, con la
 * fecha del último aviso, si toca otro.
 *
 * Dos ritmos, para que el aviso no se acabe ignorando:
 * - Lo URGENTE (tiene plazo: notificación oficial, entrega a la gestoría, remesa que
 *   no ha entrado) avisa el día que aparece y otra vez cada
 *   {@see self::URGENT_EVERY_DAYS} días desde el último aviso mientras siga ahí. Se
 *   cuenta desde el último aviso y no por tramos fijos del calendario: con tramos, lo
 *   que aparecía el último día de uno volvía a avisar al día siguiente. Una
 *   notificación oficial cuenta como notificada a los 10 días, así que en ese plazo
 *   insiste cuatro veces (al llegar y a los 3, 6 y 9 días).
 * - Lo demás (libro atrasado, facturas por revisar…) va en un solo resumen semanal.
 */
final class AccountingWatchPlan
{
    /** Cada cuántos días se repite el aviso de una tarea urgente que sigue sin resolver. */
    public const URGENT_EVERY_DAYS = 3;

    /**
     * Las tareas que apremian.
     *
     * @param list<AgendaItem> $items Lo que dice hoy la portada.
     *
     * @return list<AgendaItem>
     */
    public function urgent(array $items): array
    {
        return array_values(array_filter($items, static fn (AgendaItem $item): bool => AgendaUrgency::Soon === $item->urgency));
    }

    /**
     * Las que no apremian: van al resumen de la semana.
     *
     * @param list<AgendaItem> $items Lo que dice hoy la portada.
     *
     * @return list<AgendaItem>
     */
    public function weekly(array $items): array
    {
        return array_values(array_filter($items, static fn (AgendaItem $item): bool => AgendaUrgency::Soon !== $item->urgency));
    }

    /**
     * ¿Toca avisar hoy de una tarea urgente? Sí si nunca se avisó, o si desde el
     * último aviso han pasado {@see self::URGENT_EVERY_DAYS} días. Se cuentan días de
     * calendario en la zona de hoy: la hora del aviso anterior no cuenta.
     *
     * @param \DateTimeImmutable|null $lastAlert Cuándo se avisó por última vez, o null.
     * @param \DateTimeImmutable      $today     Hoy.
     *
     * @return bool
     */
    public function isUrgentDue(?\DateTimeImmutable $lastAlert, \DateTimeImmutable $today): bool
    {
        if (null === $lastAlert) {
            return true;
        }
        $next = $lastAlert->setTimezone($today->getTimezone())->setTime(0, 0)->modify(sprintf('+%d days', self::URGENT_EVERY_DAYS));

        return $today->setTime(0, 0) >= $next;
    }

    /**
     * El lunes de la semana de hoy: la fecha con la que se apunta el resumen semanal.
     * Si el reloj falla el lunes, sale el primer día de la semana que corra.
     *
     * @param \DateTimeImmutable $today Hoy.
     *
     * @return \DateTimeImmutable
     */
    public function week(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify('monday this week');
    }
}
