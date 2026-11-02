<?php

namespace App\EventSubscriber;

use App\Controller\WorkController;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Quien ya no trabaja en la asociación sólo consulta su registro de jornada: no
 * ficha, no apunta ni corrige fichajes, no rellena días ni pide o cancela ausencias.
 *
 * Se para aquí, en un solo sitio, cualquier POST de «Mi jornada»
 * ({@see WorkController}), en vez de acción por acción: una acción nueva queda
 * cerrada sin acordarse de nada. Consultar (calendario, días, PDF) sigue abierto:
 * el registro se conserva cuatro años y la persona tiene derecho a verlo.
 */
final class WorkerOnLeaveGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    /**
     * Si es un POST a «Mi jornada» de quien tiene ficha laboral pero ya se fue, lo
     * devuelve a su panel con un aviso en vez de ejecutar la acción.
     */
    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $request = $event->getRequest();
        $user = $this->security->getUser();
        if (!\is_array($controller) || !$controller[0] instanceof WorkController || !$request->isMethod('POST')
            || !$user instanceof User || $user->getWorker() === null || $user->isActiveWorker()) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'Ya no trabajas en la asociación: puedes consultar tu registro, pero no cambiarlo.');
        }
        $url = $this->urls->generate('work');
        $event->setController(static fn (): RedirectResponse => new RedirectResponse($url));
    }
}
