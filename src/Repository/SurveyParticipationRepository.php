<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\Survey;
use App\Entity\SurveyParticipation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyParticipation>
 */
class SurveyParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyParticipation::class);
    }

    /**
     * ¿Este socix ya participó en esta encuesta? Comprobación blanda para la
     * UI (esconder el formulario, mostrar "ya respondiste"). La garantía dura
     * contra duplicados es el UNIQUE de la tabla, no esto.
     */
    public function hasParticipated(Survey $survey, Partner $partner): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.survey = :survey')
            ->andWhere('p.partner = :partner')
            ->setParameter('survey', $survey)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Número de socixs que han participado en la encuesta. Es el denominador
     * natural de los porcentajes en los resultados.
     */
    public function countForSurvey(Survey $survey): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.survey = :survey')
            ->setParameter('survey', $survey)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
