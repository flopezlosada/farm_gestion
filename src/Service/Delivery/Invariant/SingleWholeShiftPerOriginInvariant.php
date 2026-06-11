<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerDeliveryShift;

/**
 * L21 — Sin shifts duplicados: como mucho UN cambio de entrega entera
 * (component=null) por socio y semana de origen. El unique de BBDD no lo
 * garantiza (MySQL trata los NULL de component_id como distintos, ver nota
 * en la entidad); lo garantiza el applier al crear, y esta ley vigila que
 * no se haya colado un duplicado contradictorio por otro camino.
 */
final class SingleWholeShiftPerOriginInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L21';
    }

    public function name(): string
    {
        return 'Máximo un shift de entrega entera por socio y semana origen';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, fb.date AS fdate, COUNT(s.id) AS n
             FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.partner p
             JOIN s.fromBasket fb
             WHERE s.component IS NULL AND fb.date >= :from
             GROUP BY p.id, fb.id
             HAVING COUNT(s.id) > 1'
        )->setParameter('from', $from)->getArrayResult();

        $violations = array_map(
            fn (array $r): string => sprintf(
                '%s (%d): %d shifts de entrega entera desde la semana del %s — estado ambiguo.',
                $r['pname'],
                $r['pid'],
                $r['n'],
                $this->d($r['fdate'])
            ),
            $rows
        );

        // Origen = destino: sin sentido (el constructor de la entidad lo
        // prohíbe, pero hay datos anteriores a la regla — caso CRISTINA 500→500).
        $selfShifts = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, fb.date AS fdate
             FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.partner p
             JOIN s.fromBasket fb
             WHERE s.toBasket = s.fromBasket AND fb.date >= :from'
        )->setParameter('from', $from)->getArrayResult();
        foreach ($selfShifts as $r) {
            $violations[] = sprintf(
                '%s (%d): shift con origen = destino (semana del %s) — dato sin sentido.',
                $r['pname'],
                $r['pid'],
                $this->d($r['fdate'])
            );
        }

        return $violations;
    }
}
