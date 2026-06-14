<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\DeliveryException;
use App\Entity\WeeklyBasket;

/**
 * L8 — Cierre ⇒ cero entregas: una DeliveryException de cancelación (sin
 * fecha trasladada) significa que ese alcance NO reparte esa semana — global
 * (festivo/puente: nadie) o de un nodo concreto. Cualquier WeeklyBasket
 * dentro del alcance cancelado es estado ilegal (p. ej. un cierre registrado
 * DESPUÉS de generar la semana sin regenerarla — el agujero que motivó la
 * materialización tardía).
 */
final class ClosedWeekEmptyInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L8';
    }

    public function name(): string
    {
        return 'Semana cerrada sin entregas (cancelación global o de nodo)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var DeliveryException[] $exceptions */
        $exceptions = $this->em->createQuery(
            'SELECT e, b, n FROM ' . DeliveryException::class . ' e
             JOIN e.basket b
             LEFT JOIN e.node n
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getResult();

        $violations = [];
        foreach ($exceptions as $e) {
            if (!$e->isCancelled()) {
                continue;
            }

            $dql = 'SELECT p.id AS pid, p.name AS pname
                    FROM ' . WeeklyBasket::class . ' wb
                    JOIN wb.partner p
                    LEFT JOIN wb.weekly_basket_group g
                    WHERE wb.basket = :basket';
            $params = ['basket' => $e->getBasket()];
            if (!$e->isGlobal()) {
                $dql .= ' AND g.node = :node';
                $params['node'] = $e->getNode();
            }
            $rows = $this->em->createQuery($dql)->setParameters($params)->getArrayResult();

            foreach ($rows as $r) {
                $violations[] = sprintf(
                    '%s (%d): tiene WB en la semana del %s pese al cierre %s.',
                    $r['pname'],
                    $r['pid'],
                    $this->d($e->getBasket()?->getDate()),
                    $e->isGlobal() ? 'GLOBAL' : 'del nodo ' . ($e->getNode()?->getName() ?? '¿?')
                );
            }
        }

        return $violations;
    }
}
