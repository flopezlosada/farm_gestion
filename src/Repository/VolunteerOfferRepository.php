<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre las DEFINICIONES de trabajo.
 *
 * Aquí no hay fechas. Todo lo que se pregunta con un calendario delante —qué
 * viene, qué falta por cerrar, qué se hizo— es una pregunta sobre turnos y vive
 * en {@see VolunteerShiftRepository}. Este repositorio sirve a la pantalla de
 * mantenimiento: qué trabajos tiene definidos la asociación, en qué estado están
 * y cuál hay que retocar.
 *
 * @extends ServiceEntityRepository<VolunteerOffer>
 */
class VolunteerOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerOffer::class);
    }

    /**
     * El catálogo de tareas con sus filtros, como QueryBuilder para poder
     * paginarlo igual que el de socixs.
     *
     * Se ordena por TÍTULO y no por fecha, a propósito: una definición no tiene
     * fecha, y esta pantalla se usa para buscar "la del invernadero" y
     * retocarla. Lo que se ordena por fecha es el listado de turnos.
     *
     * @param string                       $scope      published | draft | paused | cancelled | all
     * @param VolunteerCategory|null       $category   filtra por área
     * @param string|null                  $query      texto libre sobre título y explicación
     * @param list<VolunteerCategory>|null $restrictTo áreas a las que se limita quien mira; null = sin límite
     */
    public function listQb(
        string $scope = 'published',
        ?VolunteerCategory $category = null,
        ?string $query = null,
        ?array $restrictTo = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('o')
            ->orderBy('o.title', 'ASC');

        if (\in_array($scope, VolunteerOffer::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $scope);
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
            $qb->andWhere('LOWER(o.title) LIKE :q OR LOWER(o.description) LIKE :q OR LOWER(o.placeNote) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb;
    }

    /**
     * Cuántas tareas hay en cada estado, para la tira de cifras del catálogo.
     *
     * @param list<VolunteerCategory>|null $restrictTo áreas a las que se limita quien mira
     *
     * @return array{published: int, draft: int, paused: int, cancelled: int, all: int}
     */
    public function counts(?array $restrictTo = null): array
    {
        $count = fn (string $scope): int => (int) $this->listQb($scope, null, null, $restrictTo)
            ->select('COUNT(DISTINCT o.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'published' => $count(VolunteerOffer::STATUS_PUBLISHED),
            'draft' => $count(VolunteerOffer::STATUS_DRAFT),
            'paused' => $count(VolunteerOffer::STATUS_PAUSED),
            'cancelled' => $count(VolunteerOffer::STATUS_CANCELLED),
            'all' => $count('all'),
        ];
    }

    /**
     * Las tareas que se repiten y siguen vivas, para que el generador pueda
     * extenderles la serie sin repasarlas todas.
     *
     * Incluye las que están EN PAUSA a propósito: una tarea pausada vuelve, y
     * cuando vuelva tiene que tener turnos; lo que la pausa impide es pedir
     * gente, no existir en el calendario.
     *
     * DEJA FUERA EL MONTAJE DE LAS CESTAS, que no es una tarea con receta
     * editable sino un derivado de lo que declara su punto de recogida: lo
     * mantiene {@see \App\Service\Volunteering\DeliveryPrepOffers}, recorriendo
     * los puntos. Y ahí está la razón de fondo de que se excluya: el montaje
     * nace EN BORRADOR, así que el filtro de estado de aquí lo dejaría fuera de
     * todos modos hasta que alguien lo publicara — y entonces su horizonte no se
     * extendería, sin error y sin aviso.
     *
     * @return list<VolunteerOffer> las tareas con receta de repetición
     */
    public function findRepeating(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.repeatType != :once')
            ->andWhere('o.status IN (:alive)')
            ->andWhere('o.deliveryPrep = false')
            ->setParameter('once', VolunteerOffer::REPEAT_ONCE)
            ->setParameter('alive', [VolunteerOffer::STATUS_PUBLISHED, VolunteerOffer::STATUS_PAUSED])
            ->orderBy('o.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La convocatoria de montaje de un punto de recogida, si la tiene.
     *
     * Un punto tiene como mucho una: la crea el sistema a partir de lo que el
     * punto declara y la reutiliza siempre. Que no haya un índice único
     * garantizándolo es deliberado —haría falta una segunda clave ajena al mismo
     * nodo, y dos columnas apuntando al mismo sitio pueden discrepar—; quien
     * garantiza que no se dupliquen es {@see \App\Service\Volunteering\DeliveryPrepOffers},
     * que busca antes de crear.
     *
     * No filtra por estado: la de un punto que dejó de montar está en pausa y
     * hay que encontrarla igual, o al reencenderlo se crearía una segunda y la
     * gente apuntada a la primera se quedaría en una convocatoria fantasma.
     *
     * @param Node $node el punto de recogida
     *
     * @return VolunteerOffer|null su convocatoria de montaje, o null si nunca ha tenido
     */
    public function findDeliveryPrepOffer(Node $node): ?VolunteerOffer
    {
        return $this->findOneBy(['node' => $node, 'deliveryPrep' => true]);
    }
}
