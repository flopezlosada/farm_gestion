<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;

/**
 * L27 — Expiración aplicada: ningún PBS sigue activo con end_date ya vencida.
 *
 * Complementa a L10: aquélla vigila la PIEDRA (que no haya entregas tras la
 * baja); ésta vigila el ESTADO de la suscripción. Cubre los dos caminos de
 * finalizar cesta: con fecha pasada (el controller desactiva al momento) y
 * con fecha futura programada (la desactiva finalizeExpiredShares dentro de
 * la generación, cuya frontera es el viernes de la semana en curso — ver
 * PartnerBasketShareRepository::findFinalized).
 *
 * Tolerancia anti-falso-positivo: entre que la end_date vence y la SIGUIENTE
 * generación corre, el PBS queda legítimamente activo-y-vencido. Por eso solo
 * es violación si la end_date es anterior a HOY y además anterior a la última
 * semana con piedra (si hubo generación posterior al vencimiento, debió
 * expirarlo).
 */
final class ExpiredShareDeactivatedInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L27';
    }

    public function name(): string
    {
        return 'PBS con end_date vencida no sigue activo';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $maxStone = $this->em->createQuery(
            'SELECT MAX(b.date) FROM ' . WeeklyBasket::class . ' wb JOIN wb.basket b'
        )->getSingleScalarResult();

        if ($maxStone === null) {
            return [];
        }

        $expired = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, pbs.end_date AS ended
             FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             WHERE pbs.is_active = 1
               AND pbs.end_date IS NOT NULL
               AND pbs.end_date < :today
               AND pbs.end_date < :maxStone'
        )
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('maxStone', $maxStone)
            ->getArrayResult();

        $violations = [];
        foreach ($expired as $r) {
            $violations[] = sprintf(
                '%s (%d): PBS activo con end_date vencida el %s y generación posterior (finalizeExpiredShares no lo expiró).',
                $r['pname'],
                $r['pid'],
                $this->d($r['ended'])
            );
        }

        return $violations;
    }
}
