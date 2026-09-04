<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre los momentos de trabajo.
 *
 * AQUÍ VIVEN LAS PREGUNTAS CON FECHA, que antes estaban en
 * {@see VolunteerOfferRepository} cuando la tarea llevaba la fecha encima. Todo
 * lo que un socix ve —qué hay esta semana, a qué me puedo apuntar, qué se hizo—
 * es una pregunta sobre turnos; la tarea sólo dice qué es el trabajo.
 *
 * EL FILTRO DE "TIENE SITIO" NO VA EN SQL. Depende de las inscripciones vivas y
 * de los acompañantes, y su regla ya vive en {@see VolunteerShift::hasRoom()}.
 * Duplicarla en un GROUP BY sería tener dos definiciones de lo mismo, y la de
 * SQL se quedaría atrás sin que nadie lo notara — además del patrón que ya dio
 * problemas con `ONLY_FULL_GROUP_BY` en este repo.
 *
 * @extends ServiceEntityRepository<VolunteerShift>
 */
class VolunteerShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerShift::class);
    }

    /**
     * Base común: turnos de tareas publicadas y en pie, ni anulados ni de
     * tareas anuladas o en pausa.
     *
     * Una tarea EN PAUSA no aporta turnos futuros a ninguna de estas listas, que
     * es exactamente para lo que sirve pausar; sus turnos pasados sí siguen
     * apareciendo donde toca, porque el trabajo se hizo.
     *
     * @return QueryBuilder con el alias `s` y la tarea ya unida como `o`
     */
    private function liveQb(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.offer', 'o')
            ->addSelect('o')
            ->andWhere('s.cancelledAt IS NULL');
    }

    /**
     * Los turnos publicados que aún no han empezado, del más próximo al más
     * lejano. Es la lista base: de aquí sale lo que ve un socix y sobre esto
     * trabaja el escalado de avisos.
     *
     * @param \DateTimeInterface $from  momento a partir del cual se consideran futuros
     * @param int|null           $limit número máximo de turnos; null para todos
     *
     * @return list<VolunteerShift> los turnos abiertos, por fecha ascendente
     */
    public function findUpcoming(\DateTimeInterface $from, ?int $limit = null): array
    {
        $qb = $this->liveQb()
            ->andWhere('o.status = :published')
            ->andWhere('s.startsAt > :from')
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('from', $from)
            ->orderBy('s.startsAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Como {@see findUpcoming()}, pero con el orden del panel del socix: lo
     * destacado por quien coordina, después lo que ocurre en su punto de
     * recogida y después por fecha.
     *
     * Ese orden no es cosmético. Quien recoge su cesta en La Cabrera ya va a
     * estar allí ese día, así que un turno en su nodo es el de menor fricción
     * posible; enterrarlo bajo otros tres que le pillan a cuarenta kilómetros es
     * perder el único que iba a aceptar. Y por encima queda lo destacado, que es
     * el único modo que tiene quien coordina de decir "esta semana, esto".
     *
     * @param \DateTimeInterface $from  momento a partir del cual se consideran futuros
     * @param Node|null          $node  el punto de recogida del socix, si tiene
     * @param int|null           $limit número máximo de turnos; null para todos
     *
     * @return list<VolunteerShift> los turnos abiertos: destacados, del nodo, y por fecha
     */
    public function findUpcomingForNode(\DateTimeInterface $from, ?Node $node, ?int $limit = null): array
    {
        $shifts = $this->findUpcoming($from);

        // Ordenación en PHP y no en SQL: son unas pocas decenas de filas y un
        // CASE WHEN en el ORDER BY obligaría a arrastrar el nodo como parámetro
        // por toda la consulta para ganar nada medible.
        usort($shifts, static function (VolunteerShift $a, VolunteerShift $b) use ($node): int {
            $featured = (($b->getOffer()?->isFeatured() ?? false) ? 1 : 0)
                <=> (($a->getOffer()?->isFeatured() ?? false) ? 1 : 0);
            if (0 !== $featured) {
                return $featured;
            }

            if (null !== $node) {
                $mine = ($b->getOffer()?->getNode() === $node ? 1 : 0)
                    <=> ($a->getOffer()?->getNode() === $node ? 1 : 0);
                if (0 !== $mine) {
                    return $mine;
                }
            }

            return $a->getStartsAt() <=> $b->getStartsAt();
        });

        return null === $limit ? $shifts : \array_slice($shifts, 0, $limit);
    }

    /**
     * Lo que de verdad le hace falta a quien mira: como
     * {@see findUpcomingForNode()}, pero quitando los turnos que ya están llenos
     * y aquellos a los que esa persona ya se ha apuntado.
     *
     * El límite se aplica DESPUÉS de filtrar: pedir tres a la consulta y
     * descartar dos después dejaba la home con una sola tarea habiendo más
     * disponibles, y ése fue un fallo real.
     *
     * @param \DateTimeInterface $from            momento a partir del cual se consideran futuros
     * @param Node|null          $node            el punto de recogida de quien mira, si tiene
     * @param list<int|null>     $excludeShiftIds ids de turnos a los que ya se apuntó
     * @param int|null           $limit           número máximo de turnos; null para todos
     *
     * @return list<VolunteerShift> lo que sigue sin cubrir, los de su nodo primero
     */
    public function findStillNeededFor(
        \DateTimeInterface $from,
        ?Node $node,
        array $excludeShiftIds = [],
        ?int $limit = null,
    ): array {
        $needed = array_values(array_filter(
            $this->findUpcomingForNode($from, $node),
            static fn (VolunteerShift $shift): bool => $shift->hasRoom()
                && !\in_array($shift->getId(), $excludeShiftIds, true)
        ));

        return null === $limit ? $needed : \array_slice($needed, 0, $limit);
    }

    /**
     * Los turnos que caen entre dos fechas, para el calendario de tareas.
     *
     * Incluye los turnos anulados a propósito: en un calendario hay que poder
     * ver que ese día NO se hace, o quien lo mire pensará que se olvidó de
     * crearlo. Quien pinte decide cómo enseñarlos.
     *
     * @param \DateTimeInterface $from            desde, incluido
     * @param \DateTimeInterface $to              hasta, incluido
     * @param bool                         $publishedOnly sólo turnos de tareas publicadas
     * @param VolunteerCategory|null       $category      sólo los de tareas de esta área
     * @param VolunteerOffer|null          $offer         sólo los de esta tarea
     * @param list<VolunteerCategory>|null $restrictTo    áreas a las que se limita quien mira; null = sin límite
     *
     * @return list<VolunteerShift> los turnos del rango, por fecha ascendente
     */
    public function findBetween(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        bool $publishedOnly = true,
        ?VolunteerCategory $category = null,
        ?VolunteerOffer $offer = null,
        ?array $restrictTo = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.offer', 'o')
            ->addSelect('o')
            ->andWhere('s.startsAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.startsAt', 'ASC');

        if ($publishedOnly) {
            $qb->andWhere('o.status = :published')
                ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED);
        }

        if (null !== $offer) {
            $qb->andWhere('s.offer = :offer')->setParameter('offer', $offer);
        }

        $this->inCategory($qb, $category);
        $this->restrictTo($qb, $restrictTo);

        return $qb->getQuery()->getResult();
    }

    /**
     * Sólo los turnos de tareas de un área.
     *
     * EXISTS y no JOIN: con un join sobre las áreas el turno saldría repetido
     * una vez por área de su tarea.
     *
     * @param QueryBuilder           $qb       consulta con la tarea como alias `o`
     * @param VolunteerCategory|null $category el área; null = sin filtro
     */
    private function inCategory(QueryBuilder $qb, ?VolunteerCategory $category): void
    {
        if (null === $category) {
            return;
        }

        $qb->andWhere($qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerOffer of2 JOIN of2.categories c2'
            .' WHERE of2 = o AND c2 = :category'
        ))->setParameter('category', $category);
    }

    /**
     * Restricción por áreas propias (quien coordina, no administración).
     *
     * Va en el repositorio y no en los controllers para que ninguna vista futura
     * pueda saltársela por descuido: el fallo de un filtro de permisos no da
     * error, simplemente enseña lo que no debía.
     *
     * Una lista VACÍA no significa "todas": significa que esta persona no
     * coordina ninguna área, y entonces no ve ningún turno.
     *
     * @param QueryBuilder                 $qb         consulta con la tarea como alias `o`
     * @param list<VolunteerCategory>|null $restrictTo áreas permitidas; null = sin límite
     */
    private function restrictTo(QueryBuilder $qb, ?array $restrictTo): void
    {
        if (null === $restrictTo) {
            return;
        }

        if ([] === $restrictTo) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere($qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerOffer of3 JOIN of3.categories c3'
            .' WHERE of3 = o AND c3 IN (:mine)'
        ))->setParameter('mine', $restrictTo);
    }

    /**
     * El montaje del reparto de un nodo para una entrega concreta: los turnos de
     * tareas publicadas de una categoría marcada como
     * {@see \App\Entity\VolunteerCategory::isDeliveryPrep()}, de ese nodo, que
     * caen en la víspera o el mismo día de la recogida.
     *
     * Sirve para contarle a cada socix quién le prepara su cesta esa semana, y
     * para avisarle cuando no se ha apuntado nadie. Que el disparador sea LA
     * TAREA y no la falta de gente es lo que hace que el aviso no aparezca en
     * los nodos donde el montaje todavía no se organiza así: sin tarea no hay
     * tarjeta, ni de nombres ni de aviso.
     *
     * LA VENTANA ES DE DOS DÍAS porque las cestas se montan a veces la tarde
     * anterior. Ensancharla es seguro justamente porque el filtro de categoría
     * ya excluye cualquier otra tarea del nodo —limpiar el local, por ejemplo—.
     *
     * @param Node               $node         el punto de recogida donde recoge esa semana
     * @param \DateTimeInterface $deliveryDate el día en que recoge la cesta
     *
     * @return list<VolunteerShift> el montaje de ese reparto, de lo más temprano en adelante
     */
    public function findDeliveryPrepFor(Node $node, \DateTimeInterface $deliveryDate): array
    {
        $day = \DateTimeImmutable::createFromInterface($deliveryDate);

        return $this->liveQb()
            ->andWhere('o.status = :published')
            ->andWhere('o.node = :node')
            ->andWhere('s.startsAt BETWEEN :from AND :to')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerOffer op JOIN op.categories cp'
                .' WHERE op = o AND cp.deliveryPrep = true'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('node', $node)
            ->setParameter('from', $day->modify('-1 day')->setTime(0, 0))
            ->setParameter('to', $day->setTime(23, 59, 59))
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Los turnos que ya se han hecho: pasados y con al menos una persona que
     * confirmó que fue. Del más reciente al más antiguo.
     *
     * Es la cara visible de que el voluntariado funciona, y por eso se enseña.
     * No lleva ranking: lo que mueve es ver que se hacen cosas, no quién las
     * hace — un listado de quién más aporta convertiría a la asociación en una
     * competición y expulsaría a quien no puede competir.
     *
     * @param \DateTimeInterface $from  desde cuándo mirar atrás
     * @param int                $limit cuántos devolver
     *
     * @return list<VolunteerShift> los turnos hechos, del más reciente atrás
     */
    public function findRecentlyDone(\DateTimeInterface $from, int $limit = 5): array
    {
        return $this->liveQb()
            ->andWhere('o.status = :published')
            ->andWhere('s.startsAt BETWEEN :from AND :now')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerSignup sd WHERE sd.shift = s AND sd.attended = true'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('from', $from)
            ->setParameter('now', new \DateTime())
            ->orderBy('s.startsAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Los turnos publicados que ya han pasado y siguen sin cerrar: nadie ha
     * dicho todavía quién fue y quién no. Son los que hay que recordar a quien
     * coordina, porque mientras no se cierren no computan horas a nadie.
     *
     * Con EXISTS y no con un JOIN + GROUP BY: el JOIN devolvería una fila por
     * inscripción y obligaría a agrupar por el turno entero, que es justo el
     * patrón que ya dio problemas con `ONLY_FULL_GROUP_BY` en este repo (#16).
     *
     * @param \DateTimeInterface $until momento hasta el que se consideran pasados
     *
     * @return list<VolunteerShift> los turnos pasados pendientes de cerrar
     */
    public function findPendingClosure(\DateTimeInterface $until): array
    {
        return $this->liveQb()
            ->andWhere('o.status = :published')
            ->andWhere('s.startsAt <= :until')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerSignup sp'
                .' WHERE sp.shift = s AND sp.attended IS NULL AND sp.cancelledAt IS NULL'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('until', $until)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * El listado de gestión, como QueryBuilder para poder paginarlo igual que el
     * de socixs.
     *
     * ES DE TURNOS Y NO DE TAREAS, y ése es el cambio de fondo: las cuatro
     * vistas son las cuatro preguntas que se hacen de verdad —qué viene, qué
     * está sin confirmar, qué se hizo y qué se quedó sin cubrir— y las cuatro
     * son preguntas sobre momentos. Un listado de definiciones no puede
     * contestarlas: "sacar al perro" no viene ni pasó, viene mañana a las nueve.
     * Las definiciones tienen su propia pantalla
     * ({@see VolunteerOfferRepository::listQb()}).
     *
     * La última vista es la incómoda y por eso tiene su sitio: un turno que pasó
     * sin que fuera nadie dice más sobre cómo va el voluntariado que cualquier
     * contador de horas.
     *
     * @param string                       $scope      upcoming | pending | done | missed | all
     * @param VolunteerCategory|null       $category   filtra por área
     * @param string|null                  $query      texto libre sobre título y explicación
     * @param \DateTimeInterface|null      $now        momento de referencia
     * @param list<VolunteerCategory>|null $restrictTo áreas a las que se limita quien mira; null = sin límite
     * @param VolunteerOffer|null          $offer      sólo los turnos de esta tarea; null = de todas
     */
    public function listQb(
        string $scope = 'upcoming',
        ?VolunteerCategory $category = null,
        ?string $query = null,
        ?\DateTimeInterface $now = null,
        ?array $restrictTo = null,
        ?VolunteerOffer $offer = null,
    ): QueryBuilder {
        $now ??= new \DateTime();

        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.offer', 'o')
            ->addSelect('o');

        // La misma consulta sirve para el listado global y para la lista de UNA
        // tarea: las cinco vistas son las mismas preguntas, sólo cambia de
        // cuántas tareas se hacen.
        if (null !== $offer) {
            $qb->andWhere('s.offer = :offer')->setParameter('offer', $offer);
        }

        // EXISTS y no JOIN: con un join sobre signups el turno saldría repetido
        // una vez por persona apuntada.
        $attended = $qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerSignup sa'
            .' WHERE sa.shift = s AND sa.attended = true AND sa.role = :roleParticipant'
        );
        $unanswered = $qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerSignup su'
            .' WHERE su.shift = s AND su.attended IS NULL AND su.cancelledAt IS NULL'
        );

        switch ($scope) {
            case 'pending':
                $qb->andWhere('s.cancelledAt IS NULL')
                    ->andWhere('s.startsAt <= :now')->andWhere($unanswered)
                    ->setParameter('now', $now)
                    ->orderBy('s.startsAt', 'ASC');
                break;
            case 'done':
                $qb->andWhere('s.startsAt <= :now')->andWhere($attended)
                    ->setParameter('now', $now)
                    ->setParameter('roleParticipant', VolunteerSignup::ROLE_PARTICIPANT)
                    ->orderBy('s.startsAt', 'DESC');
                break;
            case 'missed':
                $qb->andWhere('s.cancelledAt IS NULL')
                    ->andWhere('s.startsAt <= :now')
                    ->andWhere($qb->expr()->not($attended))
                    ->andWhere($qb->expr()->not($unanswered))
                    ->setParameter('now', $now)
                    ->setParameter('roleParticipant', VolunteerSignup::ROLE_PARTICIPANT)
                    ->orderBy('s.startsAt', 'DESC');
                break;
            case 'all':
                $qb->orderBy('s.startsAt', 'DESC');
                break;
            default:
                $qb->andWhere('s.cancelledAt IS NULL')
                    ->andWhere('s.startsAt > :now')
                    ->setParameter('now', $now)
                    ->orderBy('s.startsAt', 'ASC');
        }

        $this->inCategory($qb, $category);
        $this->restrictTo($qb, $restrictTo);

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(o.title) LIKE :q OR LOWER(o.description) LIKE :q OR LOWER(o.placeNote) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb;
    }

    /**
     * Cuántos turnos hay en cada vista, para la tira de cifras del listado.
     *
     * @param \DateTimeInterface|null      $now        momento de referencia
     * @param list<VolunteerCategory>|null $restrictTo áreas a las que se limita quien mira
     * @param VolunteerOffer|null          $offer      sólo los de esta tarea; null = de todas
     *
     * @return array{upcoming: int, pending: int, done: int, missed: int, all: int}
     */
    public function counts(?\DateTimeInterface $now = null, ?array $restrictTo = null, ?VolunteerOffer $offer = null): array
    {
        $count = fn (string $scope): int => (int) $this->listQb($scope, null, null, $now, $restrictTo, $offer)
            ->select('COUNT(DISTINCT s.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'upcoming' => $count('upcoming'),
            'pending' => $count('pending'),
            'done' => $count('done'),
            'missed' => $count('missed'),
            'all' => $count('all'),
        ];
    }

    /**
     * Si ya existe un turno de esta tarea a esta hora exacta.
     *
     * Es la comprobación que hace idempotente al generador: volver a generar la
     * serie no puede duplicar turnos, y la unicidad de BBDD por sí sola
     * reventaría con una excepción en vez de saltarse los que ya están.
     *
     * @param VolunteerOffer     $offer    la tarea
     * @param \DateTimeInterface $startsAt el momento exacto
     *
     * @return bool true si ese turno ya existe
     */
    public function existsAt(VolunteerOffer $offer, \DateTimeInterface $startsAt): bool
    {
        return null !== $this->findOneBy(['offer' => $offer, 'startsAt' => $startsAt]);
    }
}
