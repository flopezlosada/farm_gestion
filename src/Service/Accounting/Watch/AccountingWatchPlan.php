<?php

namespace App\Service\Accounting\Watch;

use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;

/**
 * Qué se avisa hoy de la portada de contabilidad. Sin estado: la memoria de lo ya
 * avisado la lleva el registro de efectos de la tarea, con la fecha que esta clase le
 * da a cada aviso.
 *
 * Dos ritmos, para que el aviso no se acabe ignorando:
 * - Lo URGENTE (tiene plazo: notificación oficial, entrega a la gestoría, remesa que
 *   no ha entrado) avisa el día que aparece y otra vez en cada tramo de
 *   {@see self::URGENT_EVERY_DAYS} días mientras siga ahí. Una notificación oficial
 *   cuenta como notificada a los 10 días, así que en ese plazo insiste tres veces.
 * - Lo demás (libro atrasado, facturas por revisar…) va en un solo resumen semanal.
 */
final class AccountingWatchPlan
{
    /** Cada cuántos días se repite el aviso de una tarea urgente que sigue sin resolver. */
    public const URGENT_EVERY_DAYS = 3;

    /** Día fijo desde el que se cuentan los tramos; da igual cuál, mientras no cambie. */
    private const EPOCH = '2026-01-01';

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
     * El primer día del tramo de {@see self::URGENT_EVERY_DAYS} días en que cae hoy.
     * Es la fecha con la que se apunta el aviso urgente: dentro del tramo no se repite,
     * en el siguiente sí.
     *
     * @param \DateTimeImmutable $today Hoy.
     *
     * @return \DateTimeImmutable
     */
    public function urgentWindow(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $epoch = new \DateTimeImmutable(self::EPOCH, $today->getTimezone());
        $day = $today->setTime(0, 0);
        $days = (int) $epoch->diff($day)->format('%r%a');
        $start = $days - (($days % self::URGENT_EVERY_DAYS) + self::URGENT_EVERY_DAYS) % self::URGENT_EVERY_DAYS;

        return $epoch->modify(sprintf('%+d days', $start));
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
