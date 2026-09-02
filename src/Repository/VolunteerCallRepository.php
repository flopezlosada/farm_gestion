<?php

namespace App\Repository;

use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
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
     * Los alcances a los que ya se ha avisado por un turno. Con esto el escalado
     * sabe por dónde va sin recorrer el historial: si "matching" ya está, el
     * siguiente paso es "unspecified".
     *
     * @param VolunteerShift $shift el turno
     *
     * @return list<string> los alcances ya enviados
     */
    public function sentScopes(VolunteerShift $shift): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.scope')
            ->where('c.shift = :shift')
            ->setParameter('shift', $shift)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['scope'], $rows);
    }

    /**
     * La última llamada enviada por un turno, sea del alcance que sea. El
     * escalado la necesita para respetar el margen de espera antes de abrir el
     * aviso a más gente: sin ese margen, los dos pasos saldrían en el mismo tick
     * y el escalado no habría escalado nada.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return VolunteerCall|null la última llamada, o null si no se ha avisado aún
     */
    public function findLast(VolunteerShift $shift): ?VolunteerCall
    {
        return $this->createQueryBuilder('c')
            ->where('c.shift = :shift')
            ->setParameter('shift', $shift)
            ->orderBy('c.sentAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Los avisos mandados por cualquier turno de una tarea, del más reciente al
     * más antiguo.
     *
     * Es lo que enseña la pestaña de avisos de la ficha: de una tarea continua
     * interesa el rastro completo —a quién se le ha pedido y cuándo—, no sólo lo
     * del turno que se esté mirando.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return list<VolunteerCall> los avisos, del más reciente atrás
     */
    public function findForOffer(VolunteerOffer $offer): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.shift', 's')
            ->addSelect('s')
            ->where('s.offer = :offer')
            ->setParameter('offer', $offer)
            ->orderBy('c.sentAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
