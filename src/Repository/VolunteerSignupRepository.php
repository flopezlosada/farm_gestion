<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerSignup>
 */
class VolunteerSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerSignup::class);
    }

    /**
     * Si un socix ya está apuntado a una oferta (aunque se diera de baja). Lo
     * usa la pantalla para enseñar "apuntarse" o "darse de baja", y el alta para
     * no chocar contra la constraint de unicidad con un error feo.
     *
     * @param VolunteerOffer $offer   la oferta
     * @param Partner        $partner el socix
     *
     * @return VolunteerSignup|null la inscripción, o null si no existe
     */
    public function findOneFor(VolunteerOffer $offer, Partner $partner): ?VolunteerSignup
    {
        return $this->findOneBy(['offer' => $offer, 'partner' => $partner]);
    }

    /**
     * Minutos que un socix lleva reconocidos en un periodo. Sólo cuenta lo
     * confirmado: una inscripción sin cerrar no infla el contador de nadie.
     *
     * El periodo se mide por la fecha de la OFERTA y no por la de inscripción:
     * apuntarse en diciembre a algo que es en enero cuenta en enero, que es
     * cuando se trabaja.
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $from    inicio del periodo, inclusive
     * @param \DateTimeInterface $to      fin del periodo, inclusive
     *
     * @return int minutos reconocidos en el periodo
     */
    public function sumCreditedMinutes(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $total = $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.creditedMinutes), 0)')
            ->join('s.offer', 'o')
            ->where('s.partner = :partner')
            ->andWhere('s.attended = true')
            ->andWhere('o.startsAt BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }

    /**
     * La MEDIANA de minutos entre quienes han hecho algo en el periodo. No la
     * media, y no es un detalle.
     *
     * La media está contaminada por los ceros justo por el problema que este
     * módulo quiere resolver: si el 70% no hace nada, la media se hunde y
     * alguien que fue una tarde suelta ya sale "por encima de la media", que es
     * exactamente el mensaje contrario al que hace falta. Y peor: cada persona
     * que se activa sube la media, así que quien iba justo por encima cae por
     * debajo sin haber hecho nada distinto.
     *
     * Por eso el denominador es "quien echa una mano, echa esto", calculado
     * sobre quienes participan. A quien está a cero le sitúa lo que hace la
     * gente que participa; a los demás no les mueve el listón bajo los pies.
     *
     * Se calcula en PHP a propósito: MySQL no tiene función de mediana, las
     * recetas con variables de sesión son ilegibles, y aquí hablamos de unos
     * cientos de filas como mucho.
     *
     * @param \DateTimeInterface $from inicio del periodo, inclusive
     * @param \DateTimeInterface $to   fin del periodo, inclusive
     *
     * @return int mediana de minutos entre quienes participan; 0 si no participó nadie
     */
    public function medianCreditedMinutes(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.partner) AS partnerId, SUM(s.creditedMinutes) AS total')
            ->join('s.offer', 'o')
            ->where('s.attended = true')
            ->andWhere('o.startsAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('s.partner')
            ->having('SUM(s.creditedMinutes) > 0')
            ->getQuery()
            ->getScalarResult();

        if ([] === $rows) {
            return 0;
        }

        $totals = array_map(static fn (array $row): int => (int) $row['total'], $rows);
        sort($totals);

        $count = \count($totals);
        $middle = intdiv($count, 2);

        return 0 === $count % 2
            ? intdiv($totals[$middle - 1] + $totals[$middle], 2)
            : $totals[$middle];
    }

    /**
     * Las inscripciones vivas de un socix a ofertas que aún no han pasado, de
     * la más próxima a la más lejana. Es su "lo que tengo comprometido".
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $from    momento a partir del cual se consideran futuras
     *
     * @return list<VolunteerSignup> las inscripciones futuras no canceladas
     */
    public function findUpcomingFor(Partner $partner, \DateTimeInterface $from): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.offer', 'o')
            ->where('s.partner = :partner')
            ->andWhere('s.cancelledAt IS NULL')
            ->andWhere('o.startsAt > :from')
            ->andWhere('o.status = :published')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->orderBy('o.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
