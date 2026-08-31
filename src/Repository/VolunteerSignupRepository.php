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
     * Lo que este socix ha hecho de verdad en el periodo, de lo más reciente
     * hacia atrás. Es lo que le da carne al contador: "6 h" no dice nada, pero
     * "6 h: dos repartos y una mañana de plantación" sí.
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $from    inicio del periodo, inclusive
     * @param \DateTimeInterface $to      fin del periodo, inclusive
     *
     * @return list<VolunteerSignup> sus inscripciones confirmadas
     */
    public function findDoneFor(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.offer', 'o')
            ->where('s.partner = :partner')
            ->andWhere('s.attended = true')
            ->andWhere('o.startsAt BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('o.startsAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuántas veces ha participado cada socix en el periodo y cuándo fue la
     * última, en UNA consulta, indexado por id de socix.
     *
     * Devuelto como mapa y no como lista de entidades porque lo consume una
     * tabla de doscientas y pico filas: preguntar por cada socix sería el N+1
     * más caro de todo el módulo.
     *
     * Sólo aparece quien ha participado alguna vez; el resto no está en el mapa
     * y la pantalla lo interpreta como cero. Distinguir "cero" de "no consta" no
     * aporta nada aquí y ahorra recorrer los 246.
     *
     * Los MINUTOS viajan en el mismo mapa aunque casi ninguna pantalla los
     * pinte: son el criterio con el que la ficha de una tarea ordena a quién
     * pedirle que venga —primero quien menos lleva— y sacarlos en otra consulta
     * sería recorrer las mismas filas dos veces para agregarlas igual. `SUM`
     * sobre una columna nullable devuelve null si no hay nada que sumar, de ahí
     * el cast.
     *
     * @param \DateTimeInterface $from inicio del periodo, inclusive
     * @param \DateTimeInterface $to   fin del periodo, inclusive
     *
     * @return array<int, array{times: int, last: string, minutes: int}> id de socix => veces, última fecha y minutos
     */
    public function participationByPartner(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.partner) AS pid, COUNT(s.id) AS times, MAX(o.startsAt) AS last, SUM(s.creditedMinutes) AS minutes')
            ->join('s.offer', 'o')
            ->where('s.attended = true')
            ->andWhere('o.startsAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('s.partner')
            ->getQuery()
            ->getScalarResult();

        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[(int) $row['pid']] = [
                'times' => (int) $row['times'],
                'last' => (string) $row['last'],
                'minutes' => (int) $row['minutes'],
            ];
        }

        return $byPartner;
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
     * Las inscripciones vivas a tareas que empiezan dentro de la ventana dada:
     * a esa gente hay que recordarle que se apuntó.
     *
     * Por barrido y no programando un aviso al apuntarse (que es como lo hace
     * Karrot): así darse de baja no deja un recordatorio pendiente que haya que
     * revocar — quien se dio de baja sencillamente deja de salir en esta
     * consulta. Menos piezas y ningún aviso fantasma.
     *
     * La repetición la corta {@see \App\Service\Cron\EffectLedger}: el barrido
     * corre cada hora y la misma inscripción cae en varias pasadas.
     *
     * @param \DateTimeInterface $from  desde cuándo (normalmente, ahora)
     * @param \DateTimeInterface $until hasta cuándo mirar adelante
     *
     * @return list<VolunteerSignup> las inscripciones que toca recordar
     */
    public function findDueForReminder(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.offer', 'o')
            ->where('s.cancelledAt IS NULL')
            ->andWhere('o.status = :published')
            ->andWhere('o.startsAt BETWEEN :from AND :until')
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('o.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lo que este socix se apuntó, ya ha pasado y todavía no ha dicho si hizo o
     * no. Es lo que se le pregunta en su panel.
     *
     * Que lo conteste quien fue —y no gestión al cerrar la tarea— es lo que
     * quita el punto único de fallo: si el contador de horas dependiera de que
     * administración cierre cada tarea a mano, se olvidarían y se quedaría a
     * cero para todo el mundo sin que nadie supiera por qué.
     *
     * @param Partner            $partner el socix
     * @param \DateTimeInterface $until   momento hasta el que se consideran pasadas
     *
     * @return list<VolunteerSignup> sus inscripciones pasadas sin responder
     */
    public function findPendingConfirmationFor(Partner $partner, \DateTimeInterface $until): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.offer', 'o')
            ->where('s.partner = :partner')
            ->andWhere('s.cancelledAt IS NULL')
            ->andWhere('s.attended IS NULL')
            ->andWhere('o.startsAt <= :until')
            ->andWhere('o.status = :published')
            ->setParameter('partner', $partner)
            ->setParameter('until', $until)
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->orderBy('o.startsAt', 'DESC')
            ->getQuery()
            ->getResult();
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
