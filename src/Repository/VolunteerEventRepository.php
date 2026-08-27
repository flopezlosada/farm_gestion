<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerEvent>
 */
class VolunteerEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerEvent::class);
    }

    /**
     * El historial de UNA tarea, de lo más reciente hacia atrás.
     *
     * Sin filtro por área ni paginación a propósito: quien está viendo la ficha
     * ya ha pasado el permiso de esa tarea, y el historial de una sola tarea no
     * crece hasta necesitar páginas.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return list<VolunteerEvent> su historial
     */
    public function historyFor(VolunteerOffer $offer): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.partner', 'p')
            ->addSelect('p')
            ->where('e.offer = :offer')
            ->setParameter('offer', $offer)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * El historial de UN área: lo que le ha pasado al tipo de trabajo en sí
     * (creación, cambios, quién lo coordina), no las tareas que lo usan.
     *
     * Esas están en cada tarea, y traerlas aquí convertiría la ficha del área en
     * un segundo listado de actividad que ya existe y con filtro propio.
     *
     * @param VolunteerCategory $category el área
     *
     * @return list<VolunteerEvent> su historial
     */
    public function historyForCategory(VolunteerCategory $category): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.category = :category')
            ->setParameter('category', $category)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Traduce los actores de una lista de eventos a nombres de persona.
     *
     * El actor se guarda como texto ("gestor:1") para que el rastro sobreviva al
     * borrado de la cuenta, pero "gestor:1" no lo entiende nadie leyendo una
     * pantalla. Esto resuelve los nombres en DOS consultas —una de cuentas y
     * otra de socixs— en vez de una por fila.
     *
     * Devuelve sólo lo que ha podido resolver: quien no esté en el mapa se pinta
     * con su código, que es lo honesto cuando la cuenta ya no existe.
     *
     * @param list<VolunteerEvent> $events los eventos a resolver
     *
     * @return array<string, string> "gestor:1" => "admin"
     */
    public function actorNames(array $events): array
    {
        $userIds = [];
        $partnerIds = [];

        foreach ($events as $event) {
            $actor = (string) $event->getActor();
            if (str_starts_with($actor, 'gestor:')) {
                $userIds[] = (int) substr($actor, 7);
            } elseif (str_starts_with($actor, 'partner:')) {
                $partnerIds[] = (int) substr($actor, 8);
            }
        }

        $names = [];
        $em = $this->getEntityManager();

        if ([] !== $userIds) {
            foreach ($em->getRepository(\App\Entity\User::class)->findBy(['id' => array_unique($userIds)]) as $user) {
                $names['gestor:'.$user->getId()] = $user->getDisplayName();
            }
        }

        if ([] !== $partnerIds) {
            foreach ($em->getRepository(\App\Entity\Partner::class)->findBy(['id' => array_unique($partnerIds)]) as $partner) {
                $names['partner:'.$partner->getId()] = trim($partner->getName().' '.$partner->getSurname());
            }
        }

        return $names;
    }

    /**
     * El rastro de actividad, de lo más reciente hacia atrás, como QueryBuilder
     * para poder paginarlo.
     *
     * EL FILTRO POR ÁREA ES EL PUNTO DE LA PANTALLA: quien coordina el reparto
     * ve lo que pasa en el reparto y nada más. Un evento pertenece a un área si
     * la tarea a la que se refiere está en ella, o si el propio evento la
     * declara (los que no tienen tarea: crear un tipo de trabajo, cambiar quién
     * lo coordina).
     *
     * Los eventos SIN área ninguna —los de un socix cambiando sus preferencias—
     * quedan fuera para quien coordina y sólo los ve administración. No son de
     * nadie en particular y no hay forma honesta de repartirlos.
     *
     * @param list<VolunteerCategory>|null $restrictTo áreas de quien mira; null = ve todo
     * @param string|null                  $type       filtra por tipo de evento
     * @param Partner|null                 $partner    filtra por socix
     */
    public function feedQb(?array $restrictTo = null, ?string $type = null, ?Partner $partner = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.offer', 'o')
            ->leftJoin('e.partner', 'p')
            ->leftJoin('e.category', 'c')
            ->addSelect('o', 'p', 'c')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if (null !== $type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        // Una persona no tiene ficha propia en el módulo, así que su historial
        // es esta pantalla filtrada por ella. El filtro por área sigue encima:
        // quien coordina el reparto ve de esa persona lo del reparto y nada más.
        if (null !== $partner) {
            $qb->andWhere('e.partner = :who')->setParameter('who', $partner);
        }

        if (null !== $restrictTo) {
            if ([] === $restrictTo) {
                return $qb->andWhere('1 = 0');
            }

            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->exists(
                        'SELECT 1 FROM App\Entity\VolunteerOffer oe JOIN oe.categories ce'
                        .' WHERE oe = e.offer AND ce IN (:mine)'
                    ),
                    'e.category IN (:mine)'
                )
            )->setParameter('mine', $restrictTo);
        }

        return $qb;
    }
}
