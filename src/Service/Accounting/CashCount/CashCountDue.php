<?php

namespace App\Service\Accounting\CashCount;

use App\Entity\CashCount;
use App\Entity\FinancialAccount;

/**
 * Qué cajas están sin contar este mes. El arqueo se hace a fin de mes, así que vale
 * cualquier recuento desde la última semana del mes anterior: contar el 28 de octubre
 * es el arqueo de octubre, y en noviembre no hace falta otro hasta fin de mes.
 */
final class CashCountDue
{
    /** Días antes del 1 en que un recuento ya cuenta como el arqueo del mes que acaba. */
    public const GRACE_DAYS = 7;

    /**
     * Las cajas abiertas sin recuento desde la última semana del mes anterior. Puro.
     *
     * @param list<FinancialAccount> $open   Cajas abiertas.
     * @param list<CashCount>        $latest Último recuento de cada caja (las que tienen alguno).
     *
     * @return list<FinancialAccount>
     */
    public static function pending(array $open, array $latest, \DateTimeImmutable $today): array
    {
        $since = self::since($today)->format('Y-m-d');
        $counted = [];
        foreach ($latest as $count) {
            if ($count->getCountDate()->format('Y-m-d') >= $since) {
                $counted[(int) $count->getAccount()->getId()] = true;
            }
        }

        return array_values(array_filter($open, static fn (FinancialAccount $a): bool => !isset($counted[(int) $a->getId()])));
    }

    /** Desde qué día cuenta un recuento como el arqueo del mes que acaba de cerrar. */
    public static function since(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify('first day of this month')->modify(sprintf('-%d days', self::GRACE_DAYS));
    }
}
