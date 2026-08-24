<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;

/**
 * L29 — Cesta extra en una semana cuya entrega ya NO está ahí: el override de extra
 * ({@see PartnerBasketExtra}) se quedó atrás mientras la entrega de esa semana se aparcaba
 * ("no recoge") o se iba a otra fecha (cambio de día). El día queda dibujando una cesta
 * fantasma que la proyección resucita desde el override.
 *
 * Dos variantes, misma raíz — los overrides de extra no siguen a la cesta:
 *  - APARCADA (shift sin destino): ARREGLADO en el código. {@see \App\Service\Delivery\DeliveryShiftApplier::applySkipIntent}
 *    retira los extras al dejar el día a cero. Lo que esta ley encuentre aquí es rastro
 *    HISTÓRICO anterior al arreglo (o un extra añadido a mano DESPUÉS del "no recoge",
 *    que es legítimo).
 *  - MOVIDA (shift con destino): DEUDA VIVA. Al mover la cesta de un día que llevaba una
 *    extra, el override no viaja con ella: se queda en el origen. Si la semana estaba
 *    generada, la composición sí viajó al destino, así que la extra se CUENTA DOS VECES
 *    (una en el destino, otra dibujada en el origen) → cesta de más en el listado. Lo suyo
 *    es que el extra VIAJE con la cesta (retirar el override del origen y ponerlo en el
 *    destino, antes de leer la composición que viaja para no duplicarla).
 *
 * WARNING, no ERROR: la primera variante también se alcanza a mano y de forma deliberada
 * (marcar "no recoge" y DESPUÉS añadir una cesta extra a ese mismo día desde la ficha o
 * desde el reparto). Eso el calendario lo dibuja bien —papelera con la cesta aparcada +
 * slot con la extra—, así que no puede tumbar la batería: hay que MIRAR cada caso.
 */
final class ExtraLeftBehindInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L29';
    }

    public function name(): string
    {
        return 'Cesta extra en una semana aparcada o movida a otro día';
    }

    public function severity(): string
    {
        return self::SEVERITY_WARNING;
    }

    public function check(\DateTimeImmutable $from): array
    {
        $extras = $this->em->createQuery(
            'SELECT IDENTITY(x.partner) AS pid, p.name AS pname, b.id AS bid, b.date AS bdate
             FROM ' . PartnerBasketExtra::class . ' x
             JOIN x.partner p
             JOIN x.basket b
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getArrayResult();

        if ($extras === []) {
            return [];
        }

        // Shifts de ENTREGA ENTERA que SALEN de una semana: sin destino = "no recoge"
        // (aparcada); con destino = movida a otra fecha. En ambos casos la entrega de esa
        // semana ya no está ahí. Quitar un COMPONENTE (component != null) no vacía el día,
        // así que no cuenta: convive de forma legítima con una extra.
        $shiftRows = $this->em->createQuery(
            'SELECT IDENTITY(s.partner) AS pid, IDENTITY(s.fromBasket) AS fbid, IDENTITY(s.toBasket) AS tbid
             FROM ' . PartnerDeliveryShift::class . ' s
             WHERE s.component IS NULL'
        )->getArrayResult();

        $outgoing = [];
        foreach ($shiftRows as $s) {
            // Con destino manda "movida": es la variante que descuadra el listado.
            $key = $s['pid'] . '-' . $s['fbid'];
            if ($s['tbid'] !== null || !isset($outgoing[$key])) {
                $outgoing[$key] = $s['tbid'] !== null;
            }
        }

        // Un socio puede tener VARIAS filas de extra la misma semana (una por componente):
        // se reporta la semana una sola vez.
        $seen = [];
        $violations = [];
        foreach ($extras as $x) {
            $key = $x['pid'] . '-' . $x['bid'];
            if (!isset($outgoing[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $violations[] = sprintf(
                '%s (%d): la semana del %s tiene cesta extra pero su entrega está %s.',
                $x['pname'],
                $x['pid'],
                $this->d($x['bdate']),
                $outgoing[$key] ? 'MOVIDA a otra fecha (la extra se cuenta dos veces)' : 'aparcada en "no recoge"',
            );
        }

        return $violations;
    }
}
