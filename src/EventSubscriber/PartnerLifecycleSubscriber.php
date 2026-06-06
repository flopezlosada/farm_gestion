<?php

namespace App\EventSubscriber;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Escucha las transiciones del workflow partner_lifecycle y deja rastro:
 *
 *   - Crea un PartnerEvent del tipo correspondiente (PAUSE, RESUME, LEAVE,
 *     RESUME-tras-baja como JOIN) con el actor que la disparó.
 *   - Cuando la transición es 'leave', borra los WeeklyBasket futuros del
 *     socio para que no aparezca en los listados de reparto venideros.
 *
 * La persistencia se ata al flush actual; el caller hace flush después de
 * disparar la transición. Si nunca hace flush, no se graba el evento, lo
 * cual es coherente con que tampoco se haya guardado el cambio de status.
 */
class PartnerLifecycleSubscriber implements EventSubscriberInterface
{
    /** Mapa transición -> tipo de PartnerEvent que registramos. */
    private const TRANSITION_TO_EVENT_TYPE = [
        'pause' => PartnerEvent::TYPE_PAUSE,
        'resume' => PartnerEvent::TYPE_RESUME,
        'leave' => PartnerEvent::TYPE_LEAVE,
        'rejoin' => PartnerEvent::TYPE_JOIN,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.partner_lifecycle.entered' => 'onEntered',
        ];
    }

    public function onEntered(EnteredEvent $event): void
    {
        $partner = $event->getSubject();
        if (!$partner instanceof Partner) {
            return;
        }

        $transition = $event->getTransition();
        $name = $transition?->getName();
        if ($name === null || !isset(self::TRANSITION_TO_EVENT_TYPE[$name])) {
            return;
        }

        $type = self::TRANSITION_TO_EVENT_TYPE[$name];

        $froms = $event->getTransition()->getFroms();

        $partnerEvent = new PartnerEvent($partner, $type);
        $partnerEvent->setActor($this->resolveActor());
        $partnerEvent->setPayload([
            'transition' => $name,
            'from' => $froms[0] ?? null,
        ]);

        $this->em->persist($partnerEvent);

        if ($name === 'leave') {
            $this->dropFutureWeeklyBaskets($partner);
        }
    }

    /**
     * Borra los WeeklyBasket del socio cuya cesta es de hoy en adelante.
     * Se hace cuando un socio causa baja: no debe seguir apareciendo en los
     * listados de reparto futuros. El histórico (cestas pasadas) queda intacto.
     */
    private function dropFutureWeeklyBaskets(Partner $partner): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $futureBaskets = $this->em->createQueryBuilder()
            ->select('wb')
            ->from(WeeklyBasket::class, 'wb')
            ->join('wb.basket', 'b')
            ->where('wb.partner = :partner')
            ->andWhere('b.date >= :today')
            ->setParameter('partner', $partner)
            ->setParameter('today', $today)
            ->getQuery()
            ->getResult();

        foreach ($futureBaskets as $wb) {
            $this->em->remove($wb);
        }
    }

    /**
     * Cadena identificadora para PartnerEvent::actor. Si hay un usuario en
     * sesión, "gestor:{id}"; si no, "system" (transición programática).
     */
    private function resolveActor(): string
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return PartnerEvent::ACTOR_SYSTEM;
        }
        if (method_exists($user, 'getId') && $user->getId() !== null) {
            return 'gestor:' . $user->getId();
        }
        return 'gestor:' . $user->getUserIdentifier();
    }
}
