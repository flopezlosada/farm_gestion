<?php

namespace App\Service\Partner;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resuelve el `actor` de un {@see PartnerEvent} a partir de quien está en sesión:
 *
 *   - sin usuario (cron, comando, transición programática) → "system";
 *   - el propio socix tocando lo suyo                        → "partner:{id}";
 *   - cualquier otra persona                                 → "gestor:{id}".
 *
 * Una sola convención para todos los emisores, que la ficha traduce a
 * "autoservicio", el nombre de quien gestiona o "sistema".
 */
class PartnerEventActor
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * @param Partner|null $subject Socix sobre quien se registra el evento. Si
     *        se pasa y es el socix de la cuenta en sesión, el actor es
     *        "partner:{id}"; sin él, toda persona en sesión cuenta como gestión.
     * @return string Actor en la convención de PartnerEvent::$actor.
     */
    public function resolve(?Partner $subject = null): string
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return PartnerEvent::ACTOR_SYSTEM;
        }
        if ($subject !== null && $subject->getId() !== null
            && $user instanceof User && $user->getPartner() === $subject) {
            return 'partner:' . $subject->getId();
        }
        if (method_exists($user, 'getId') && $user->getId() !== null) {
            return 'gestor:' . $user->getId();
        }

        return 'gestor:' . $user->getUserIdentifier();
    }
}
