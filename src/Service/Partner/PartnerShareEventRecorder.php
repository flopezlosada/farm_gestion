<?php

namespace App\Service\Partner;

use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Centraliza la emisión de PartnerEvent::TYPE_BASKET_START y TYPE_BASKET_END
 * cuando se crea o cierra un PartnerBasketShare. Cada caller decide el
 * contexto (fecha real + actor) según el origen del cambio:
 *
 *   - Controllers admin: actor = gestor:{id}, occurredAt = ahora.
 *   - finalizeExpiredShares (auto-cierre por end_date pasada): actor = system,
 *     occurredAt = end_date del share.
 *   - Comando de importación CLI: actor = cli, occurredAt = start_date del share.
 *
 * El servicio sólo hace persist(); el flush lo hace el caller para mantenerse
 * acoplado al mismo flush en el que se persiste el PartnerBasketShare.
 */
class PartnerShareEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /**
     * Registra el inicio de una cesta (alta de PartnerBasketShare).
     *
     * @param PartnerBasketShare $share Share recién creado.
     * @param \DateTimeInterface|null $occurredAt Cuándo arrancó. Si null, se
     *                                            usa start_date del share.
     * @param string|null $actor Quién lo hizo. Si null, se resuelve via Security.
     * @return PartnerEvent Evento persistido (no flusheado).
     */
    public function recordStart(
        PartnerBasketShare $share,
        ?\DateTimeInterface $occurredAt = null,
        ?string $actor = null,
    ): PartnerEvent {
        return $this->record(
            $share,
            PartnerEvent::TYPE_BASKET_START,
            $occurredAt ?? $share->getStartDate(),
            $actor,
        );
    }

    /**
     * Registra el cierre de una cesta (finalización de PartnerBasketShare).
     *
     * @param PartnerBasketShare $share Share que se desactiva.
     * @param \DateTimeInterface|null $occurredAt Cuándo terminó. Si null, se
     *                                            usa end_date del share.
     * @param string|null $actor Quién lo hizo. Si null, se resuelve via Security.
     * @return PartnerEvent Evento persistido (no flusheado).
     */
    public function recordEnd(
        PartnerBasketShare $share,
        ?\DateTimeInterface $occurredAt = null,
        ?string $actor = null,
    ): PartnerEvent {
        return $this->record(
            $share,
            PartnerEvent::TYPE_BASKET_END,
            $occurredAt ?? $share->getEndDate(),
            $actor,
        );
    }

    /**
     * Registra un cambio dentro de una cesta continua: cambia algún atributo
     * (modalidad, huevos, cohorte, day_month_order…) pero el socix sigue con
     * cesta. El modelo parte el histórico en dos PBS — la antigua con
     * end_date, la nueva con start_date — y este evento las une
     * semánticamente con un BASKET_CHANGE en lugar de END+START sueltos.
     *
     * @param PartnerBasketShare $oldShare PBS que se cierra.
     * @param PartnerBasketShare $newShare PBS nueva que la sustituye.
     * @param \DateTimeInterface|null $occurredAt Cuándo entró en vigor. Si null, start_date de la nueva.
     * @param string|null $actor Quién lo hizo. Si null, vía Security.
     * @return PartnerEvent Evento persistido (no flusheado).
     */
    public function recordChange(
        PartnerBasketShare $oldShare,
        PartnerBasketShare $newShare,
        ?\DateTimeInterface $occurredAt = null,
        ?string $actor = null,
    ): PartnerEvent {
        $event = new PartnerEvent(
            $newShare->getPartner(),
            PartnerEvent::TYPE_BASKET_CHANGE,
            $occurredAt ?? $newShare->getStartDate(),
        );
        $event->setActor($actor ?? $this->resolveActor());
        $event->setPayload([
            'from' => $this->snapshotShare($oldShare),
            'to'   => $this->snapshotShare($newShare),
        ]);
        $this->em->persist($event);

        return $event;
    }

    /**
     * Snapshot de los campos relevantes de una PartnerBasketShare para
     * incluir en el payload de BASKET_CHANGE. Permite reconstruir qué
     * cambió sin tener que rehidratar la entidad.
     *
     * @param PartnerBasketShare $share
     * @return array<string,mixed>
     */
    private function snapshotShare(PartnerBasketShare $share): array
    {
        return [
            'pbs_id'              => $share->getId(),
            'basket_share_id'     => $share->getBasketShare()?->getId(),
            'egg_amount_id'       => $share->getEggAmount()?->getId(),
            'egg_period_id'       => $share->getEggPeriod()?->getId(),
            'day_month_order'     => $share->getDayMonthOrder(),
            'egg_day_month_order' => $share->getEggDayMonthOrder(),
            'delivery_group'      => $share->getDeliveryGroup(),
        ];
    }

    private function record(
        PartnerBasketShare $share,
        string $type,
        ?\DateTimeInterface $occurredAt,
        ?string $actor,
    ): PartnerEvent {
        $event = new PartnerEvent($share->getPartner(), $type, $occurredAt);
        $event->setActor($actor ?? $this->resolveActor());
        $event->setPayload([
            'basket_share_id' => $share->getBasketShare()?->getId(),
            'delivery_group' => $share->getDeliveryGroup(),
        ]);
        $this->em->persist($event);

        return $event;
    }

    /**
     * Misma convención de actor que PartnerLifecycleSubscriber::resolveActor.
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
