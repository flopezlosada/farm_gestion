<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\Survey;
use App\Entity\SurveyParticipation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Survey>
 */
class SurveyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Survey::class);
    }

    /**
     * Encuestas que admiten respuestas en este momento, de la más reciente a la
     * más antigua. Es lo que ve la socia en su panel.
     *
     * @param \DateTimeInterface $now el momento de referencia
     *
     * @return Survey[]
     */
    public function findAcceptingResponses(\DateTimeInterface $now): array
    {
        return $this->acceptingResponses($now)->getQuery()->getResult();
    }

    /**
     * Las que admiten respuesta y esta socia todavía no ha contestado: lo que la
     * portada del panel le pide. En una sola consulta.
     *
     * @param Partner            $partner la socia
     * @param \DateTimeInterface $now     el momento de referencia
     *
     * @return Survey[]
     */
    public function findPendingFor(Partner $partner, \DateTimeInterface $now): array
    {
        $answered = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(SurveyParticipation::class, 'p')
            ->where('p.survey = s')
            ->andWhere('p.partner = :partner');

        return $this->acceptingResponses($now)
            ->andWhere('NOT EXISTS (' . $answered->getDQL() . ')')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getResult();
    }

    /**
     * La regla de {@see Survey::acceptsResponses()} escrita en consulta:
     * abierta, sin archivar y con el plazo sin vencer. `closes_at` guarda el día
     * a las 00:00, así que "sin vencer" es que ese día sea hoy o posterior. Si
     * cambia una, cambia la otra.
     *
     * @param \DateTimeInterface $now el momento de referencia
     */
    private function acceptingResponses(\DateTimeInterface $now): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :open')
            ->andWhere('s.archived = false')
            ->andWhere('(s.closesAt IS NULL OR s.closesAt >= :today)')
            ->setParameter('open', Survey::STATUS_OPEN)
            ->setParameter('today', \DateTimeImmutable::createFromInterface($now)->setTime(0, 0))
            ->orderBy('s.createdAt', 'DESC');
    }
}
