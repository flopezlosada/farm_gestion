<?php

namespace App\Repository;

use App\Entity\AccountEntry;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RemittanceReturn>
 */
class RemittanceReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RemittanceReturn::class);
    }

    /**
     * Las devoluciones de los recibos de una remesa, en el orden de sus recibos.
     *
     * @return list<RemittanceReturn>
     */
    public function findByRemittance(Remittance $remittance): array
    {
        return $this->withLines()
            ->andWhere('l.remittance = :remittance')->setParameter('remittance', $remittance)
            ->orderBy('l.debtorName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las que tienen algo por hacer, las más antiguas primero. Es la misma regla que
     * {@see RemittanceReturn::getStatus()}, en SQL para no traerse las resueltas.
     *
     * @return list<RemittanceReturn>
     */
    public function findOpen(?Partner $partner = null): array
    {
        $qb = $this->withLines()
            ->andWhere('x.outcome IS NULL'
                . ' OR (x.outcome = :paid AND x.paidWith IS NULL)'
                . ' OR (x.outcome = :recharge AND (x.rechargedIn IS NULL OR nr.status = :draft))')
            ->setParameter('paid', ReturnOutcome::PaidApart->value)
            ->setParameter('recharge', ReturnOutcome::Recharge->value)
            ->setParameter('draft', Remittance::STATUS_DRAFT)
            ->orderBy('r.period', 'ASC')
            ->addOrderBy('l.debtorName', 'ASC');
        if ($partner !== null) {
            $qb->andWhere('l.partner = :partner')->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Las que se van a volver a cobrar y aún no están en ninguna remesa, para meterlas
     * en un borrador de ese tipo. El grupo de consumo va APARTE: sus recibos se vuelven
     * a pasar sólo en su remesa, y en ella no entran los de cuotas. Mezclados, el abono
     * se anotaría en la partida que no es y los pedidos no sabrían de su recibo nuevo.
     * Entre los demás tipos sí se mezclan (una remesa «otros» para devoluciones).
     *
     * @param string $kind tipo del borrador ({@see Remittance::KIND_LABELS})
     *
     * @return list<RemittanceReturn>
     */
    public function findToRecharge(string $kind): array
    {
        return $this->withLines()
            ->andWhere('x.outcome = :recharge')->setParameter('recharge', ReturnOutcome::Recharge->value)
            ->andWhere('x.rechargedIn IS NULL')
            ->andWhere($kind === Remittance::KIND_CONSUMER_GROUP ? 'r.kind = :gc' : 'r.kind != :gc')
            ->setParameter('gc', Remittance::KIND_CONSUMER_GROUP)
            ->orderBy('r.period', 'ASC')
            ->addOrderBy('l.debtorName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuáles de esos apuntes ya son la devolución de algún recibo.
     *
     * @param list<AccountEntry> $entries
     *
     * @return list<int> Sus ids.
     */
    public function findLinkedEntryIds(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        return array_map('intval', $this->createQueryBuilder('x')
            ->select('IDENTITY(x.entry)')
            ->andWhere('x.entry IN (:entries)')->setParameter('entries', $entries)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Todas las de recibos de remesas de ese tipo, con lo enlazado ya cargado.
     *
     * @param string $kind tipo de remesa ({@see Remittance::KIND_LABELS})
     *
     * @return list<RemittanceReturn>
     */
    public function findByKind(string $kind): array
    {
        return $this->withLines()
            ->andWhere('r.kind = :kind')->setParameter('kind', $kind)
            ->getQuery()
            ->getResult();
    }

    /**
     * Con todo lo que pinta una devolución cargado de una vez: su recibo, la remesa, el
     * socix, los apuntes, el recibo nuevo con su remesa y quién la resolvió.
     */
    private function withLines(): QueryBuilder
    {
        return $this->createQueryBuilder('x')
            ->innerJoin('x.line', 'l')->addSelect('l')
            ->innerJoin('l.remittance', 'r')->addSelect('r')
            ->leftJoin('l.partner', 'p')->addSelect('p')
            ->leftJoin('x.entry', 'e')->addSelect('e')
            ->leftJoin('x.paidWith', 'pw')->addSelect('pw')
            ->leftJoin('x.rechargedIn', 'nl')->addSelect('nl')
            ->leftJoin('nl.remittance', 'nr')->addSelect('nr')
            ->leftJoin('x.resolvedBy', 'u')->addSelect('u');
    }
}
