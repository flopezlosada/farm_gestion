<?php

namespace App\EventListener;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\User;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Registra la fecha del último login en User::lastLogin.
 *
 * FOSUserBundle actualizaba este campo automáticamente; al retirarlo (sub-fase
 * 8.1) nadie lo volvió a tocar y `last_login` se quedaba siempre a NULL, así
 * que no había forma de saber si un socix había accedido alguna vez.
 *
 * LoginSuccessEvent lo dispara Symfony tras CUALQUIER authenticator con éxito
 * (formulario, magic-link, SSO de Google, remember-me), de modo que este único
 * listener cubre todos los caminos de entrada.
 *
 * De paso, si quien entra es un PRODUCTOR autogestionado, deja constancia en la
 * bitácora del grupo de consumo ({@see ConsumerGroupEventLog}): a la comisión
 * le interesa saber cuándo entra a su panel, no solo qué productos toca.
 */
class UpdateLastLoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConsumerGroupEventRecorder $recorder,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->setLastLogin(new \DateTime());

        $producer = $user->getProducer();
        if ($producer !== null) {
            $this->recorder->record(ConsumerGroupEventLog::KIND_PRODUCER_LOGIN, null, $user, sprintf('%s accedió a su panel de productor.', $producer));
        }

        $this->em->flush();
    }
}
