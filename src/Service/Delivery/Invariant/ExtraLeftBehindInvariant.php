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
 * Las dos variantes están ARREGLADAS en el código, cada una en su sitio de
 * {@see \App\Service\Delivery\DeliveryShiftApplier}:
 *  - APARCADA (shift sin destino): applySkipIntent / skipMovedDelivery retiran los extras al
 *    dejar el día a cero.
 *  - MOVIDA (shift con destino): move() los hace VIAJAR con la cesta (los quita del origen y
 *    los pone en el destino). Sin eso, en una semana ya generada la extra viajaba dentro de la
 *    composición Y seguía dibujándose en el origen → cesta de MÁS en el listado impreso.
 *
 * Así que lo que esta ley encuentre es rastro HISTÓRICO anterior a esos arreglos, algo hecho
 * a mano, o un camino que todavía no arrastra los overrides: los intents POR COMPONENTE
 * ({@see \App\Service\Delivery\DeliveryShiftApplier::moveComponent}) no cuentan aquí (miran
 * `component IS NULL`), y el re-apuntado por cierre de semana (repointTarget) tampoco los
 * mueve —ese caso lo vigila L25, porque una semana cerrada no reparte—.
 *
 * WARNING, no ERROR: la variante aparcada también se alcanza a mano y de forma deliberada
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
