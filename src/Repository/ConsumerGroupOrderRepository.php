<?php

namespace App\Repository;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\Producer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pedidos de las socias en las rondas del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupOrder>
 */
class ConsumerGroupOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupOrder::class);
    }

    /**
     * El pedido de una socia en una ronda, o null si aún no se ha apuntado. Lo usa
     * el panel para crear o editar (upsert) sin duplicar (respeta el unique).
     */
    public function findOneByRoundAndPartner(ConsumerGroupRound $round, Partner $partner): ?ConsumerGroupOrder
    {
        return $this->findOneBy(['round' => $round, 'partner' => $partner]);
    }

    /**
     * Apuntes de una socia listos para entregar y cuya entrega es futura (>= $from):
     * pedido colectivo CONFIRMADO y apunte PAGADO. Con ronda y líneas, ordenados por
     * fecha de entrega. Para pintar en el calendario (socio y gestor) lo que la socia
     * va a recibir. Regla de negocio: si no ha pagado, no aparece.
     *
     * @return ConsumerGroupOrder[]
     */
    public function findDeliverableUpcomingForPartner(Partner $partner, \DateTimeInterface $from): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('r', 'l')
            ->innerJoin('o.round', 'r')
            ->leftJoin('o.lines', 'l')
            ->where('o.partner = :partner')
            ->andWhere('r.confirmed = true')
            ->andWhere('r.status != :cancelled')
            ->andWhere('o.paid = true')
            ->andWhere('r.deliveryDate >= :from')
            ->setParameter('partner', $partner)
            ->setParameter('cancelled', \App\Entity\ConsumerGroupRound::STATUS_CANCELLED)
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('r.deliveryDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pedidos que hay que entregar en un NODO dentro de un rango de fechas, para
     * que quien reparte sepa qué bultos saca además de las cestas.
     *
     * Mismo filtro de entregabilidad que {@see findDeliverableUpcomingForPartner()}
     * —confirmado, no cancelado y PAGADO—, y por el mismo motivo: la hoja tiene
     * que llevar exactamente lo que se le ha prometido a la socia en su
     * calendario. Dos reglas distintas serían un pedido que aparece en un sitio y
     * no en el otro.
     *
     * EL NODO SALE DEL GRUPO DE RECOGIDA HABITUAL de la socia. Un traslado
     * puntual de esa semana mueve la cesta pero no esto: el pedido lo lleva la
     * comisión al punto de siempre.
     *
     * @param Node               $node el punto de recogida
     * @param \DateTimeInterface $from principio del rango (incluido)
     * @param \DateTimeInterface $to   final del rango (incluido)
     *
     * @return ConsumerGroupOrder[] ordenados por socia
     */
    public function findDeliverableForNodeBetween(Node $node, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('r', 'p')
            ->innerJoin('o.round', 'r')
            ->innerJoin('o.partner', 'p')
            ->innerJoin('p.weekly_basket_group', 'g')
            ->where('g.node = :node')
            ->andWhere('r.confirmed = true')
            ->andWhere('r.status != :cancelled')
            ->andWhere('o.paid = true')
            ->andWhere('r.deliveryDate BETWEEN :from AND :to')
            ->setParameter('node', $node)
            ->setParameter('cancelled', ConsumerGroupRound::STATUS_CANCELLED)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.surname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos los pedidos de una socia, con su ronda y líneas cargadas, para pintar
     * su panel (qué rondas ya tiene apuntadas y cuáles se le van a entregar). Volumen
     * pequeño (los pedidos de una persona), sin paginación.
     *
     * @return ConsumerGroupOrder[]
     */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('r', 'l')
            ->innerJoin('o.round', 'r')
            ->leftJoin('o.lines', 'l')
            ->where('o.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('r.deliveryDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * El último pedido que hizo esta socia a este mismo productor, para poder
     * ofrecerle «lo mismo que la vez pasada».
     *
     * SE BUSCA POR PRODUCTOR Y NO POR PRODUCTO SUELTO: lo que se repite es un
     * pedido entero —las dos garrafas de aceite y el bote de aceitunas—, y es la
     * forma en que la gente piensa lo que pide. Se excluye el pedido en curso
     * porque lo que se ofrece es lo ANTERIOR; si no, se propondría copiar lo que
     * ya está escrito en pantalla.
     *
     * @param Partner  $partner  la socia
     * @param Producer $producer el productor del pedido actual
     * @param int|null $exceptRoundId pedido en curso, que no cuenta como anterior
     *
     * @return ConsumerGroupOrder|null el más reciente por fecha de entrega, o null
     */
    public function findLastForPartnerAndProducer(Partner $partner, Producer $producer, ?int $exceptRoundId = null): ?ConsumerGroupOrder
    {
        // SIN fetch join de las líneas: con setMaxResults(1), el límite lo aplica
        // la base a FILAS, así que un join de colección devolvería el pedido con
        // una sola línea y el resto perdidas en silencio. Las líneas se cargan
        // después, que es una consulta más sobre un único pedido.
        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.round', 'r')
            ->where('o.partner = :partner')
            ->andWhere('r.producer = :producer')
            ->setParameter('partner', $partner)
            ->setParameter('producer', $producer)
            ->orderBy('r.deliveryDate', 'DESC')
            ->setMaxResults(1);

        if (null !== $exceptRoundId) {
            $qb->andWhere('r.id <> :except')->setParameter('except', $exceptRoundId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Nº de pedidos por ronda, en una sola consulta. Para el listado de gestión sin
     * N+1. Cuenta filas de pedido (un pedido por socia), no distingue los vacíos:
     * el recuento fino de participantes se hace en la ficha con el agregador.
     *
     * @return array<int, int> round.id => nº de pedidos
     */
    public function countByRound(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.round) AS round_id', 'COUNT(o.id) AS total')
            ->groupBy('o.round')
            ->getQuery()
            ->getArrayResult();

        $byRound = [];
        foreach ($rows as $row) {
            $byRound[(int) $row['round_id']] = (int) $row['total'];
        }

        return $byRound;
    }

    /**
     * Pedidos NO vacíos de una ronda, con sus líneas y la socia, para la vista de
     * gestión (agregado y export al productor). Volumen pequeño (una ronda).
     *
     * @return ConsumerGroupOrder[]
     */
    public function findWithLinesForRound(ConsumerGroupRound $round): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('l', 'p')
            ->leftJoin('o.lines', 'l')
            ->leftJoin('o.partner', 'p')
            ->where('o.round = :round')
            ->setParameter('round', $round)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.surname', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
