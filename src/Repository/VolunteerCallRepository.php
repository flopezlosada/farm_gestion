<?php

namespace App\Repository;

use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerCall>
 */
class VolunteerCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerCall::class);
    }

    /**
     * Los alcances a los que ya se ha avisado por una oferta. Con esto el
     * escalado sabe por dónde va sin recorrer el historial: si "matching" ya
     * está, el siguiente paso es "unspecified".
     *
     * @param VolunteerOffer $offer la oferta
     *
     * @return list<string> los alcances ya enviados
     */
    public function sentScopes(VolunteerOffer $offer): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.scope')
            ->where('c.offer = :offer')
            ->setParameter('offer', $offer)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['scope'], $rows);
    }

    /**
     * La última llamada enviada por una oferta, sea del alcance que sea. El
     * escalado la necesita para respetar el margen de espera antes de abrir el
     * aviso a más gente: sin ese margen, los dos pasos saldrían en el mismo tick
     * y el escalado no habría escalado nada.
     *
     * @param VolunteerOffer $offer la oferta
     *
     * @return VolunteerCall|null la última llamada, o null si no se ha avisado aún
     */
    public function findLast(VolunteerOffer $offer): ?VolunteerCall
    {
        return $this->createQueryBuilder('c')
            ->where('c.offer = :offer')
            ->setParameter('offer', $offer)
            ->orderBy('c.sentAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
