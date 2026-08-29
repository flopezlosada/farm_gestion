<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerOffer>
 */
class VolunteerOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerOffer::class);
    }

    /**
     * El listado de tareas con sus filtros, como QueryBuilder para poder
     * paginarlo igual que el de socixs.
     *
     * Las cuatro vistas son las cuatro preguntas que se hacen de verdad: qué
     * viene, qué está sin confirmar, qué se hizo y qué se quedó sin cubrir. La
     * última es la incómoda y por eso tiene su sitio: una tarea que pasó sin que
     * fuera nadie dice más sobre cómo va el voluntariado que cualquier contador.
     *
     * @param string                        $scope      upcoming | pending | done | missed | all
     * @param VolunteerCategory|null        $category   filtra por área
     * @param string|null                   $query      texto libre sobre título y explicación
     * @param \DateTimeInterface|null       $now        momento de referencia
     * @param list<VolunteerCategory>|null  $restrictTo áreas a las que se limita quien mira; null = sin límite
     */
    public function listQb(
        string $scope = 'upcoming',
        ?VolunteerCategory $category = null,
        ?string $query = null,
        ?\DateTimeInterface $now = null,
        ?array $restrictTo = null,
    ): QueryBuilder {
        $now ??= new \DateTime();

        $qb = $this->createQueryBuilder('o');

        // EXISTS y no JOIN: con un join sobre signups la tarea saldría repetida
        // una vez por persona apuntada.
        $attended = $qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerSignup sa'
            .' WHERE sa.offer = o AND sa.attended = true AND sa.role = :roleParticipant'
        );
        $unanswered = $qb->expr()->exists(
            'SELECT 1 FROM App\Entity\VolunteerSignup su'
            .' WHERE su.offer = o AND su.attended IS NULL AND su.cancelledAt IS NULL'
        );

        switch ($scope) {
            case 'pending':
                $qb->andWhere('o.startsAt <= :now')->andWhere($unanswered)
                    ->setParameter('now', $now)
                    ->orderBy('o.startsAt', 'ASC');
                break;
            case 'done':
                $qb->andWhere('o.startsAt <= :now')->andWhere($attended)
                    ->setParameter('now', $now)
                    ->setParameter('roleParticipant', VolunteerSignup::ROLE_PARTICIPANT)
                    ->orderBy('o.startsAt', 'DESC');
                break;
            case 'missed':
                $qb->andWhere('o.startsAt <= :now')
                    ->andWhere($qb->expr()->not($attended))
                    ->andWhere($qb->expr()->not($unanswered))
                    ->setParameter('now', $now)
                    ->setParameter('roleParticipant', VolunteerSignup::ROLE_PARTICIPANT)
                    ->orderBy('o.startsAt', 'DESC');
                break;
            case 'all':
                $qb->orderBy('o.startsAt', 'DESC');
                break;
            default:
                $qb->andWhere('o.startsAt > :now')
                    ->setParameter('now', $now)
                    ->orderBy('o.startsAt', 'ASC');
        }

        if (null !== $category) {
            $qb->andWhere($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerOffer of2 JOIN of2.categories c2'
                .' WHERE of2 = o AND c2 = :category'
            ))->setParameter('category', $category);
        }

        // Restricción por áreas propias (quien coordina, no administración).
        // Va aquí y no en el controller para que ninguna vista futura pueda
        // saltársela por descuido: el fallo de un filtro de permisos no da
        // error, simplemente enseña lo que no debía.
        //
        // Una lista VACÍA no significa "todas": significa que esta persona no
        // coordina ninguna área, y entonces no ve ninguna tarea.
        if (null !== $restrictTo) {
            if ([] === $restrictTo) {
                return $qb->andWhere('1 = 0');
            }

            $qb->andWhere($qb->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerOffer of3 JOIN of3.categories c3'
                .' WHERE of3 = o AND c3 IN (:mine)'
            ))->setParameter('mine', $restrictTo);
        }

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(o.title) LIKE :q OR LOWER(o.description) LIKE :q OR LOWER(o.place) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb;
    }

    /**
     * Cuántas tareas hay en cada vista, para la tira de cifras del listado.
     *
     * @param \DateTimeInterface|null      $now        momento de referencia
     * @param list<VolunteerCategory>|null $restrictTo áreas a las que se limita quien mira
     *
     * @return array{upcoming: int, pending: int, done: int, missed: int, all: int}
     */
    public function counts(?\DateTimeInterface $now = null, ?array $restrictTo = null): array
    {
        $count = fn (string $scope): int => (int) $this->listQb($scope, null, null, $now, $restrictTo)
            ->select('COUNT(DISTINCT o.id)')
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
     * Las ofertas publicadas que aún no han empezado, de la más próxima a la más
     * lejana. Es la lista base: de aquí sale lo que ve un socix y sobre esto
     * trabaja el escalado de avisos.
     *
     * NO filtra las que ya están llenas: eso depende de las inscripciones vivas
     * y se resuelve en {@see VolunteerOffer::hasRoom()}, que es donde vive esa
     * regla. Filtrarlo en SQL obligaría a un GROUP BY sobre signups no
     * cancelados y a duplicar aquí una lógica que ya está en el dominio.
     *
     * @param \DateTimeInterface $from momento a partir del cual se consideran futuras
     * @param int|null           $limit número máximo de ofertas; null para todas
     *
     * @return list<VolunteerOffer> las ofertas abiertas, por fecha ascendente
     */
    public function findUpcoming(\DateTimeInterface $from, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->where('o.status = :published')
            ->andWhere('o.startsAt > :from')
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('from', $from)
            ->orderBy('o.startsAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Como {@see findUpcoming()}, pero con el orden del panel del socix: lo
     * destacado por quien coordina, después lo que ocurre en su punto de recogida
     * y después por fecha.
     *
     * Ese orden no es cosmético. Quien recoge su cesta en La Cabrera ya va a
     * estar allí ese día, así que una tarea en su nodo es la de menor fricción
     * posible; enterrarla bajo otras tres que le pillan a cuarenta kilómetros es
     * perder la única que iba a aceptar. Y por encima queda lo destacado, que es
     * el único modo que tiene quien coordina de decir "esta semana, esto".
     *
     * @param \DateTimeInterface $from momento a partir del cual se consideran futuras
     * @param Node|null          $node el punto de recogida del socix, si tiene
     * @param int|null           $limit número máximo de ofertas; null para todas
     *
     * @return list<VolunteerOffer> las ofertas abiertas: destacadas, del nodo, y por fecha
     */
    public function findUpcomingForNode(\DateTimeInterface $from, ?Node $node, ?int $limit = null): array
    {
        $offers = $this->findUpcoming($from);

        // Ordenación en PHP y no en SQL: son unas pocas decenas de filas y un
        // CASE WHEN en el ORDER BY obligaría a arrastrar el nodo como parámetro
        // por toda la consulta para ganar nada medible.
        //
        // TRES CRITERIOS, EN ESTE ORDEN. Primero lo que ha destacado quien
        // coordina: es una decisión deliberada sobre una semana concreta y tiene
        // que poder ganarle al orden automático, o destacar no sirve de nada.
        // Después lo del punto de recogida propio, que es la fricción más baja
        // que existe. Y por último la fecha. Como destacar es escaso por
        // naturaleza, en la práctica esto son una o dos tareas arriba y el orden
        // de siempre debajo.
        usort($offers, static function (VolunteerOffer $a, VolunteerOffer $b) use ($node): int {
            $destacada = ($b->isFeatured() ? 1 : 0) <=> ($a->isFeatured() ? 1 : 0);
            if (0 !== $destacada) {
                return $destacada;
            }

            if (null !== $node) {
                $mine = ($b->getNode() === $node ? 1 : 0) <=> ($a->getNode() === $node ? 1 : 0);
                if (0 !== $mine) {
                    return $mine;
                }
            }

            return $a->getStartsAt() <=> $b->getStartsAt();
        });

        return null === $limit ? $offers : \array_slice($offers, 0, $limit);
    }

    /**
     * Lo que de verdad le hace falta a quien mira: como
     * {@see findUpcomingForNode()}, pero quitando las tareas que ya están llenas
     * y aquellas a las que esa persona ya se ha apuntado.
     *
     * Existe porque el filtro vivía suelto en el panel de voluntariado mientras
     * la home del panel llamaba a findUpcomingForNode() a pelo, y acabó
     * anunciando bajo el título «Hace falta una mano» una tarea con las dos
     * plazas cubiertas ("faltan 0 personas") — y podía ofrecerle apuntarse a
     * algo a lo que ya iba. Con una sola definición, las dos pantallas no pueden
     * volver a discrepar.
     *
     * El límite se aplica DESPUÉS de filtrar, que es la otra mitad del fallo:
     * pedir tres a la consulta y descartar dos después dejaba la home con una
     * sola tarea habiendo más disponibles.
     *
     * @param \DateTimeInterface $from            momento a partir del cual se consideran futuras
     * @param Node|null          $node            el punto de recogida de quien mira, si tiene
     * @param list<int|null>     $excludeOfferIds ids de tareas a las que ya se apuntó
     * @param int|null           $limit           número máximo de ofertas; null para todas
     *
     * @return list<VolunteerOffer> lo que sigue sin cubrir, las de su nodo primero
     */
    public function findStillNeededFor(
        \DateTimeInterface $from,
        ?Node $node,
        array $excludeOfferIds = [],
        ?int $limit = null,
    ): array {
        $needed = array_values(array_filter(
            $this->findUpcomingForNode($from, $node),
            static fn (VolunteerOffer $offer): bool => $offer->hasRoom()
                && !\in_array($offer->getId(), $excludeOfferIds, true)
        ));

        return null === $limit ? $needed : \array_slice($needed, 0, $limit);
    }

    /**
     * El montaje del reparto de un nodo para una entrega concreta: las tareas
     * publicadas de una categoría marcada como {@see VolunteerCategory::isDeliveryPrep()},
     * de ese nodo, que caen en la víspera o el mismo día de la recogida.
     *
     * Sirve para contarle a cada socix quién le prepara su cesta esa semana, y
     * para avisarle cuando no se ha apuntado nadie. Que el disparador sea LA
     * TAREA y no la falta de gente es lo que hace que el aviso no aparezca en
     * los nodos donde el montaje todavía no se organiza así: sin tarea no hay
     * tarjeta, ni de nombres ni de aviso. Hoy sólo Torremocha, y el día que otro
     * punto empiece funciona solo, sin tocar esto.
     *
     * LA VENTANA ES DE DOS DÍAS porque las cestas se montan a veces la tarde
     * anterior. Ensancharla es seguro justamente porque el filtro de categoría
     * ya excluye cualquier otra tarea del nodo —limpiar el local, por ejemplo—,
     * que es lo que rompería la lectura si esto se infiriera de "hay tarea en tu
     * nodo ese día".
     *
     * @param Node               $node         el punto de recogida donde recoge esa semana
     * @param \DateTimeInterface $deliveryDate el día en que recoge la cesta
     *
     * @return list<VolunteerOffer> el montaje de ese reparto, de lo más temprano en adelante
     */
    public function findDeliveryPrepFor(Node $node, \DateTimeInterface $deliveryDate): array
    {
        $day = \DateTimeImmutable::createFromInterface($deliveryDate);

        return $this->createQueryBuilder('o')
            ->where('o.status = :published')
            ->andWhere('o.node = :node')
            ->andWhere('o.startsAt BETWEEN :from AND :to')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerOffer op JOIN op.categories cp'
                .' WHERE op = o AND cp.deliveryPrep = true'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('node', $node)
            ->setParameter('from', $day->modify('-1 day')->setTime(0, 0))
            ->setParameter('to', $day->setTime(23, 59, 59))
            ->orderBy('o.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las tareas que ya se han hecho: pasadas y con al menos una persona que
     * confirmó que fue. De la más reciente a la más antigua.
     *
     * Es la cara visible de que el voluntariado funciona, y por eso se enseña.
     * No lleva nombres ni ranking: lo que mueve es ver que se hacen cosas, no
     * quién las hace — un listado de quién más aporta convertiría a la
     * asociación en una competición y expulsaría a quien no puede competir.
     *
     * @param \DateTimeInterface $from  desde cuándo mirar atrás
     * @param int                $limit cuántas devolver
     *
     * @return list<VolunteerOffer> las tareas hechas, de la más reciente atrás
     */
    public function findRecentlyDone(\DateTimeInterface $from, int $limit = 5): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :published')
            ->andWhere('o.startsAt BETWEEN :from AND :now')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerSignup s WHERE s.offer = o AND s.attended = true'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('from', $from)
            ->setParameter('now', new \DateTime())
            ->orderBy('o.startsAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Las ofertas publicadas que ya han pasado y siguen sin cerrar: nadie ha
     * dicho todavía quién fue y quién no. Son las que hay que recordar a quien
     * coordina, porque mientras no se cierren no computan horas a nadie.
     *
     * Con EXISTS y no con un JOIN + GROUP BY: el JOIN devolvería una fila por
     * inscripción y obligaría a agrupar por la oferta entera, que es justo el
     * patrón que ya dio problemas con `ONLY_FULL_GROUP_BY` en este repo (#16).
     *
     * @param \DateTimeInterface $until momento hasta el que se consideran pasadas
     *
     * @return list<VolunteerOffer> las ofertas pasadas pendientes de cerrar
     */
    public function findPendingClosure(\DateTimeInterface $until): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :published')
            ->andWhere('o.startsAt <= :until')
            ->andWhere($this->createQueryBuilder('x')->expr()->exists(
                'SELECT 1 FROM App\Entity\VolunteerSignup s'
                .' WHERE s.offer = o AND s.attended IS NULL AND s.cancelledAt IS NULL'
            ))
            ->setParameter('published', VolunteerOffer::STATUS_PUBLISHED)
            ->setParameter('until', $until)
            ->orderBy('o.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
