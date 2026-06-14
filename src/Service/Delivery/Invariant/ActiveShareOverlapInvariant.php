<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;

/**
 * L28 — El cambio de cesta acota el contrato anterior: un socio no tiene dos
 * PBS activos cuyos rangos [start_date, end_date] se solapen.
 *
 * Es la ley del caso Cristina (13): el cambio de modalidad/huevos crea un PBS
 * nuevo y debe dejar el viejo acotado con end_date (viejo hasta 31/05, nuevo
 * desde 01/06 → sin solape → limpio). Si el viejo queda sin end_date, o con
 * un rango que pisa al nuevo, hay dos contratos vigentes a la vez y el
 * resolver de huevos/cestas no sabe cuál manda.
 *
 * Reparto de papeles con sus vecinas: L27 vigila que el viejo, ya vencido,
 * se DESACTIVE cuando corre la generación; ésta vigila que al crearse el
 * nuevo el viejo quede ACOTADO. Sólo mira pares ACTIVOS: la historia ya
 * asentada (is_active = 0) no compite por ninguna fecha.
 *
 * Existe un chequeo pariente ("socio con más de un PBS activo") en
 * ValidatePartnersConsistencyCommand, fuera de la batería; cuando ese
 * comando se consolide aquí, esta ley lo absorbe.
 */
final class ActiveShareOverlapInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L28';
    }

    public function name(): string
    {
        return 'Cambio de cesta acota el contrato anterior (PBS activos sin solape)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $pairs = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname,
                    a.id AS aid, a.start_date AS astart, a.end_date AS aend,
                    b.id AS bid, b.start_date AS bstart, b.end_date AS bend
             FROM ' . PartnerBasketShare::class . ' a, ' . PartnerBasketShare::class . ' b, ' . Partner::class . ' p
             WHERE IDENTITY(a.partner) = p.id AND IDENTITY(b.partner) = p.id AND b.id > a.id
               AND a.is_active = 1 AND b.is_active = 1
               AND (a.start_date IS NULL OR b.end_date IS NULL OR a.start_date <= b.end_date)
               AND (b.start_date IS NULL OR a.end_date IS NULL OR b.start_date <= a.end_date)'
        )->getArrayResult();

        $violations = [];
        foreach ($pairs as $r) {
            $violations[] = sprintf(
                '%s (%d): PBS %d [%s → %s] y PBS %d [%s → %s] activos a la vez — el cambio de cesta no acotó el contrato anterior.',
                $r['pname'],
                $r['pid'],
                $r['aid'],
                $this->d($r['astart']),
                $r['aend'] ? $this->d($r['aend']) : 'abierto',
                $r['bid'],
                $this->d($r['bstart']),
                $r['bend'] ? $this->d($r['bend']) : 'abierto'
            );
        }

        return $violations;
    }
}
