<?php

namespace App\EventListener;

use App\Entity\User;
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
 */
class UpdateLastLoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
        $this->em->flush();
    }
}
