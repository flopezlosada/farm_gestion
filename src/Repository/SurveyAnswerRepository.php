<?php

namespace App\Repository;

use App\Entity\Question;
use App\Entity\SurveyAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyAnswer>
 */
class SurveyAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyAnswer::class);
    }

    /**
     * Conteo de respuestas por opción de una pregunta single/multiple.
     * Devuelve un mapa option_id => número de veces elegida. Las opciones que
     * nadie eligió no aparecen (el caller las completa a 0 desde la lista de
     * opciones de la pregunta).
     *
     * @return array<int, int>
     */
    public function countByOption(Question $question): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.option) AS optionId', 'COUNT(a.id) AS total')
            ->andWhere('a.question = :question')
            ->andWhere('a.option IS NOT NULL')
            ->setParameter('question', $question)
            ->groupBy('a.option')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['optionId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Conteo de respuestas por valor de una pregunta de escala (1-5).
     * Devuelve un mapa valor => número de veces. Los valores sin votos no
     * aparecen.
     *
     * @return array<int, int>
     */
    public function countByScaleValue(Question $question): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.valueInt AS value', 'COUNT(a.id) AS total')
            ->andWhere('a.question = :question')
            ->andWhere('a.valueInt IS NOT NULL')
            ->setParameter('question', $question)
            ->groupBy('a.valueInt')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['value']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Todos los textos libres de una pregunta de tipo texto, para listarlos en
     * los resultados. No se agregan: se leen a mano.
     *
     * @return string[]
     */
    public function findTextAnswers(Question $question): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.valueText')
            ->andWhere('a.question = :question')
            ->andWhere('a.valueText IS NOT NULL')
            ->setParameter('question', $question)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'valueText');
    }
}
