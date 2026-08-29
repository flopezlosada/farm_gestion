<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * El único sitio que escribe el rastro de actividad del voluntariado.
 *
 * Mismo planteamiento que {@see \App\Service\Partner\PartnerShareEventRecorder}:
 * un servicio con métodos que se leen como lo que pasó, y que resuelve solo
 * quién lo hizo. Que el actor se calcule aquí y no en cada llamada es la
 * diferencia entre un rastro fiable y uno con la mitad de las filas sin firmar.
 *
 * SÓLO HACE persist(), no flush. El evento se guarda en el mismo flush que el
 * cambio que lo provoca, de modo que no puede quedar un evento de algo que al
 * final no se guardó — ni al revés.
 *
 * @see VolunteerEvent para el vocabulario de tipos y la convención de actor
 */
class VolunteerEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /**
     * Registra algo que le pasó a una tarea.
     *
     * @param VolunteerOffer            $offer   la tarea
     * @param string                    $type    uno de VolunteerEvent::TYPE_*
     * @param array<string, mixed>|null $payload lo que varía según el tipo
     * @param Partner|null              $partner a quién afecta, si aplica
     */
    public function forOffer(
        VolunteerOffer $offer,
        string $type,
        ?array $payload = null,
        ?Partner $partner = null,
    ): VolunteerEvent {
        return $this->record(
            (new VolunteerEvent())
                ->setOffer($offer)
                ->setPartner($partner),
            $type,
            $payload
        );
    }

    /**
     * Registra algo que le pasó a un área: se creó, se editó, cambió quién la
     * coordina.
     *
     * @param VolunteerCategory         $category el área
     * @param string                    $type     uno de VolunteerEvent::TYPE_*
     * @param array<string, mixed>|null $payload  lo que varía según el tipo
     */
    public function forCategory(
        VolunteerCategory $category,
        string $type,
        ?array $payload = null,
    ): VolunteerEvent {
        return $this->record(
            (new VolunteerEvent())->setCategory($category),
            $type,
            $payload
        );
    }

    /**
     * Registra algo que hizo un socix y que no cuelga de ninguna tarea, como
     * cambiar de qué quiere que se le avise.
     *
     * @param Partner                   $partner el socix
     * @param string                    $type    uno de VolunteerEvent::TYPE_*
     * @param array<string, mixed>|null $payload lo que varía según el tipo
     */
    public function forPartner(
        Partner $partner,
        string $type,
        ?array $payload = null,
    ): VolunteerEvent {
        return $this->record(
            (new VolunteerEvent())->setPartner($partner),
            $type,
            $payload
        );
    }

    /**
     * Pone tipo, datos y actor, y lo deja persistido a la espera del flush del
     * caller.
     *
     * @param VolunteerEvent            $event   el evento a medio construir
     * @param string                    $type    uno de VolunteerEvent::TYPE_*
     * @param array<string, mixed>|null $payload lo que varía según el tipo
     */
    private function record(VolunteerEvent $event, string $type, ?array $payload): VolunteerEvent
    {
        $event->setType($type)
            ->setPayload($payload)
            ->setActor($this->currentActor());

        $this->em->persist($event);

        return $event;
    }

    /**
     * Quién está haciendo esto, con la convención de PartnerEvent.
     *
     * Un socix operando desde su panel se firma como "partner:{id}" y no como
     * "gestor:{id}", aunque técnicamente sea el mismo User: en el rastro
     * importa desde dónde se hizo, porque no es lo mismo que alguien se apunte
     * a que le apunte administración.
     *
     * @return string el actor
     */
    private function currentActor(): string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return VolunteerEvent::ACTOR_SYSTEM;
        }

        // Quien no gestiona voluntariado sólo puede estar operando desde su
        // propio panel.
        if (!$this->security->isGranted('ROLE_GESTION_VOLUNTARIADO') && null !== $user->getPartner()) {
            return sprintf('partner:%d', $user->getPartner()->getId());
        }

        return sprintf('gestor:%d', $user->getId());
    }
}
