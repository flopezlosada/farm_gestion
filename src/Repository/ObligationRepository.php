<?php

namespace App\Repository;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre las obligaciones que hay que mantener vigentes.
 *
 * Todas parten del mismo sitio: el vencimiento de una obligación es el fin del
 * ÚLTIMO de sus periodos, no una columna. Eso obliga a una subconsulta con MAX
 * en vez de un `WHERE o.expiresOn <= :x`, y es el precio de que renovar sea
 * añadir una fila en lugar de editar una fecha que alguien tiene que recordar.
 *
 * @extends ServiceEntityRepository<Obligation>
 */
class ObligationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Obligation::class);
    }

    /**
     * Todo lo que está bajo vigilancia, con sus periodos ya cargados y ordenado
     * por urgencia: primero lo que menos tiempo tiene, y al final lo que no
     * tiene fecha.
     *
     * Las que no tienen ningún periodo van AL FINAL y no al principio, aunque
     * sean las más incompletas: no están vencidas, están a medias, y ponerlas
     * arriba en rojo enseña a no mirar la lista.
     *
     * @param bool $includeArchived Si se incluyen las retiradas de la vigilancia.
     * @return Obligation[]
     */
    public function findWatched(bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.terms', 't')->addSelect('t')
            ->leftJoin('o.responsible', 'u')->addSelect('u')
            ->addOrderBy('o.name', 'ASC');

        if (!$includeArchived) {
            $qb->andWhere('o.archived = false');
        }

        $obligations = $qb->getQuery()->getResult();

        // El orden por urgencia se hace en PHP y no en SQL a propósito: el
        // criterio es el fin del ÚLTIMO periodo, que en SQL exige agregar y
        // arrastrar el MAX por toda la consulta sólo para ordenar. Son decenas
        // de filas, no miles.
        usort($obligations, static function (Obligation $a, Obligation $b): int {
            $left = $a->daysLeft();
            $right = $b->daysLeft();

            return match (true) {
                $left === null && $right === null => strcasecmp($a->getName(), $b->getName()),
                $left === null => 1,
                $right === null => -1,
                default => $left <=> $right,
            };
        });

        return $obligations;
    }

    /**
     * Las que vencen de aquí a `$days` días, INCLUIDAS las que ya vencieron.
     *
     * Es un "dentro de" y no un "exactamente ese día" porque el aviso tiene que
     * sobrevivir a que el reloj externo se caiga: con un umbral exacto, una
     * semana sin ticks se lleva por delante el aviso de los 90 días y nadie se
     * entera hasta los 60. Lo que evita el aviso repetido es el guardián de
     * idempotencia, no la consulta.
     *
     * Las que no tienen ningún periodo quedan fuera: no hay fecha que avisar.
     *
     * @param int                     $days  Días de antelación.
     * @param \DateTimeInterface|null $today Día de referencia (por defecto, hoy).
     * @return Obligation[]
     */
    public function findExpiringWithin(int $days, ?\DateTimeInterface $today = null): array
    {
        $from = \DateTimeImmutable::createFromInterface($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $limit = $from->modify(sprintf('+%d days', $days));

        $obligations = $this->createQueryBuilder('o')
            ->leftJoin('o.terms', 't')->addSelect('t')
            ->leftJoin('o.responsible', 'u')->addSelect('u')
            ->andWhere('o.archived = false')
            // Sin periodos, el MAX es NULL y la comparación también: la fila se
            // queda fuera sola, sin necesidad de excluirla aparte.
            ->andWhere('(SELECT MAX(tm.endsOn) FROM ' . ObligationTerm::class . ' tm WHERE tm.obligation = o) <= :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->getResult();

        usort(
            $obligations,
            static fn (Obligation $a, Obligation $b): int => $a->daysLeft($from) <=> $b->daysLeft($from)
        );

        return $obligations;
    }

    /**
     * Cuántas hay en cada estado, para la tira de cifras de la pantalla.
     *
     * @param int $noticeDays Plazo de aviso con el que se juzga "por vencer".
     * @return array{total: int, valid: int, due: int, expired: int, unknown: int}
     */
    public function countsByState(int $noticeDays): array
    {
        $counts = [
            'total' => 0,
            Obligation::STATE_VALID => 0,
            Obligation::STATE_DUE => 0,
            Obligation::STATE_EXPIRED => 0,
            Obligation::STATE_UNKNOWN => 0,
        ];

        foreach ($this->findWatched() as $obligation) {
            ++$counts['total'];
            ++$counts[$obligation->state($noticeDays)];
        }

        return $counts;
    }
}
