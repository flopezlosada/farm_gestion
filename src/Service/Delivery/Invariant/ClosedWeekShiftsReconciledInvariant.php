<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\DeliveryException;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;

/**
 * L20 — Cierre sin shifts huérfanos: cuando una semana se cierra en global,
 * {@see \App\Service\Delivery\ClosureShiftReconciler} re-apunta los shifts
 * entrantes a la siguiente operativa del nodo ("todo se corre una semana"),
 * limpia los "no recoge" redundantes y anula los salientes cuyo destino siga
 * en dibujo. Esta ley vigila ese estado final: si un cierre entra por un
 * camino que no reconcilia (import, SQL, código futuro) o el reconciliador
 * falla, los huérfanos salen aquí.
 *
 * Caso LEGAL que se tolera: shift saliente de la semana cerrada cuyo destino
 * ya está materializado para el socio (el reconciliador lo deja a propósito
 * para no provocar doble entrega). Los intents por componente quedan fuera,
 * igual que en el reconciliador. Los cierres de UN nodo no reconcilian hoy
 * (el reconciliador solo opera en globales) y tampoco se juzgan.
 */
final class ClosedWeekShiftsReconciledInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L20';
    }

    public function name(): string
    {
        return 'Cierre global sin shifts huérfanos (reconciliados)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var DeliveryException[] $exceptions */
        $exceptions = $this->em->createQuery(
            'SELECT e, b FROM ' . DeliveryException::class . ' e
             JOIN e.basket b
             WHERE b.date >= :from AND e.node IS NULL'
        )->setParameter('from', $from)->getResult();

        $violations = [];
        foreach ($exceptions as $closure) {
            if (!$closure->isCancelled()) {
                continue;
            }
            $week = $closure->getBasket();

            /** @var PartnerDeliveryShift[] $shifts */
            $shifts = $this->em->createQuery(
                'SELECT s, p, fb, tb FROM ' . PartnerDeliveryShift::class . ' s
                 JOIN s.partner p
                 JOIN s.fromBasket fb
                 LEFT JOIN s.toBasket tb
                 WHERE s.component IS NULL AND (s.fromBasket = :week OR s.toBasket = :week)'
            )->setParameter('week', $week)->getResult();

            foreach ($shifts as $shift) {
                $partner = $shift->getPartner();
                $weekLabel = $this->d($week->getDate());

                if ($shift->getToBasket()?->getId() === $week->getId()) {
                    $violations[] = sprintf(
                        '%s (%d): shift con DESTINO en la semana cerrada del %s — debió re-apuntarse a la siguiente operativa.',
                        $partner->getName(),
                        $partner->getId(),
                        $weekLabel
                    );
                    continue;
                }

                // Saliente (from = semana cerrada).
                if ($shift->getToBasket() === null) {
                    $violations[] = sprintf(
                        '%s (%d): "no recoge" sobre la semana cerrada del %s — redundante, debió limpiarse.',
                        $partner->getName(),
                        $partner->getId(),
                        $weekLabel
                    );
                    continue;
                }
                $destinationDelivered = $this->em->createQuery(
                    'SELECT COUNT(wb.id) FROM ' . WeeklyBasket::class . ' wb
                     WHERE wb.basket = :to AND wb.partner = :partner'
                )->setParameter('to', $shift->getToBasket())
                 ->setParameter('partner', $partner)
                 ->getSingleScalarResult() > 0;
                if (!$destinationDelivered) {
                    $violations[] = sprintf(
                        '%s (%d): shift saliente de la semana cerrada del %s con destino %s aún en dibujo — debió anularse (cae al desplazamiento natural).',
                        $partner->getName(),
                        $partner->getId(),
                        $weekLabel,
                        $this->d($shift->getToBasket()->getDate())
                    );
                }
                // Destino ya materializado: caso "kept" legal del reconciliador.
            }
        }

        return $violations;
    }
}
