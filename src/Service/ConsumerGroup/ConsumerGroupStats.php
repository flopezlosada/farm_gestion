<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Analítica del grupo de consumo. Calcula agregados con consultas SQL (GROUP BY),
 * sin cargar entidades en memoria. Las cifras "reales" (dinero movido, participación)
 * se miden solo sobre pedidos CONFIRMADOS (no cancelados): los apuntes de pedidos
 * abiertos-sin-confirmar o cancelados no son ventas. "Confirmado" es un flag, no un
 * estado del plazo (un pedido puede estar confirmado y aún abierto).
 *
 * El importe se calcula como Σ(cantidad × precio) sobre las líneas; informativo.
 */
class ConsumerGroupStats
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Cifras globales.
     *
     * @return array{
     *     roundsByStatus: array<int, int>,
     *     confirmed: int,
     *     confirmationRate: float|null,
     *     totalMoney: float,
     *     orders: int,
     *     participants: int,
     *     avgTicket: float|null
     * }
     */
    public function global(): array
    {
        $byStatus = [];
        $rows = $this->em->createQuery(
            'SELECT r.status AS status, COUNT(r.id) AS total
             FROM App\Entity\ConsumerGroupRound r
             GROUP BY r.status'
        )->getArrayResult();
        foreach ($rows as $row) {
            $byStatus[(int) $row['status']] = (int) $row['total'];
        }

        $confirmed = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\ConsumerGroupRound r
             WHERE r.confirmed = true AND r.status != :cancelled'
        )->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)->getSingleScalarResult();

        $agg = $this->em->createQuery(
            'SELECT COALESCE(SUM(l.quantity * ri.price), 0) AS money,
                    COUNT(DISTINCT o.id) AS orders,
                    COUNT(DISTINCT o.partner) AS participants
             FROM App\Entity\ConsumerGroupOrderLine l
             JOIN l.order o
             JOIN o.round r
             JOIN l.roundItem ri
             WHERE r.confirmed = true AND r.status != :cancelled AND l.quantity > 0'
        )->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)->getSingleResult();

        $orders = (int) $agg['orders'];
        $money = round((float) $agg['money'], 2);

        return [
            'roundsByStatus' => $byStatus,
            'confirmed' => $confirmed,
            'confirmationRate' => self::confirmationRate($confirmed, $byStatus[ConsumerGroupRound::STATUS_CANCELLED] ?? 0),
            'totalMoney' => $money,
            'orders' => $orders,
            'participants' => (int) $agg['participants'],
            'avgTicket' => $orders > 0 ? round($money / $orders, 2) : null,
        ];
    }

    /**
     * Agregado por productor sobre pedidos confirmados.
     *
     * @return list<array{name: string, rounds: int, orders: int, money: float}>
     */
    public function byProducer(): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.name AS name,
                    COUNT(DISTINCT r.id) AS rounds,
                    COUNT(DISTINCT o.id) AS orders,
                    COALESCE(SUM(l.quantity * ri.price), 0) AS money
             FROM App\Entity\ConsumerGroupOrderLine l
             JOIN l.order o
             JOIN o.round r
             JOIN r.producer p
             JOIN l.roundItem ri
             WHERE r.confirmed = true AND r.status != :cancelled AND l.quantity > 0
             GROUP BY p.id
             ORDER BY money DESC'
        )->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)->getArrayResult();

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'rounds' => (int) $r['rounds'],
            'orders' => (int) $r['orders'],
            'money' => round((float) $r['money'], 2),
        ], $rows);
    }

    /**
     * Agregado por producto sobre pedidos confirmados.
     *
     * @return list<array{name: string, unit: string, quantity: float, money: float, rounds: int, avgPrice: float|null}>
     */
    public function byProduct(): array
    {
        $rows = $this->em->createQuery(
            'SELECT prod.name AS name, prod.unit AS unit,
                    COALESCE(SUM(l.quantity), 0) AS quantity,
                    COALESCE(SUM(l.quantity * ri.price), 0) AS money,
                    COUNT(DISTINCT r.id) AS rounds
             FROM App\Entity\ConsumerGroupOrderLine l
             JOIN l.roundItem ri
             JOIN ri.product prod
             JOIN ri.round r
             WHERE r.confirmed = true AND r.status != :cancelled AND l.quantity > 0
             GROUP BY prod.id
             ORDER BY money DESC'
        )->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)->getArrayResult();

        return array_map(static function (array $r): array {
            $qty = round((float) $r['quantity'], 2);
            $money = round((float) $r['money'], 2);
            return [
                'name' => (string) $r['name'],
                'unit' => (string) $r['unit'],
                'quantity' => $qty,
                'money' => $money,
                'rounds' => (int) $r['rounds'],
                'avgPrice' => $qty > 0 ? round($money / $qty, 2) : null,
            ];
        }, $rows);
    }

    /**
     * Agregado por socia sobre pedidos confirmados (participación y gasto acumulado).
     *
     * @return list<array{name: string, rounds: int, money: float}>
     */
    public function byPartner(): array
    {
        $rows = $this->em->createQuery(
            "SELECT CONCAT(pa.name, ' ', COALESCE(pa.surname, '')) AS name,
                    COUNT(DISTINCT r.id) AS rounds,
                    COALESCE(SUM(l.quantity * ri.price), 0) AS money
             FROM App\Entity\ConsumerGroupOrderLine l
             JOIN l.order o
             JOIN o.partner pa
             JOIN o.round r
             JOIN l.roundItem ri
             WHERE r.confirmed = true AND r.status != :cancelled AND l.quantity > 0
             GROUP BY pa.id
             ORDER BY money DESC"
        )->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)->getArrayResult();

        return array_map(static fn (array $r): array => [
            'name' => trim((string) $r['name']),
            'rounds' => (int) $r['rounds'],
            'money' => round((float) $r['money'], 2),
        ], $rows);
    }

    /**
     * Tasa de confirmación: pedidos confirmados / (confirmados + cancelados). Null si
     * no hay ninguno decidido aún (nada que medir). Lógica pura, testeable.
     */
    public static function confirmationRate(int $confirmed, int $cancelled): ?float
    {
        $decided = $confirmed + $cancelled;

        return $decided > 0 ? round($confirmed / $decided, 4) : null;
    }
}
