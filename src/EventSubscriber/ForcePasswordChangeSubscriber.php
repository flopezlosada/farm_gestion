<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Obliga a (re)establecer la contraseña antes de usar nada de la aplicación.
 *
 * Cuando un User autenticado tiene passwordSet = false —porque acaba de entrar
 * por primera vez con un magic-link, o porque la administración se lo ha
 * forzado— este listener lo reconduce a la pantalla {@see app_account_password}
 * en cada petición, hasta que elija una contraseña válida.
 *
 * Es global a propósito: el gating no puede vivir solo en PanelController
 * (los socixs) porque las cuentas de gestión navegan por /gestion y nunca
 * pasarían por ese guard. Un único listener cubre socixs y equipo.
 *
 * No afecta a quien entra por Google: el SSO marca passwordSet = true (ver
 * PartnerUserProvisioner), así que esas cuentas nunca caen en este camino.
 */
final class ForcePasswordChangeSubscriber
{
    /** Ruta de la pantalla de (re)establecer contraseña. */
    private const PASSWORD_ROUTE = 'app_account_password';

    /**
     * Rutas que NO se redirigen, para no encerrar al usuario: la propia
     * pantalla de contraseña (evita el bucle) y el logout (poder salir).
     *
     * OJO: app_post_login NO va aquí a propósito. Tras el login se pasa por
     * /post-login, y queremos que ESE salto también se intercepte para
     * reconducir a quien tenga la contraseña pendiente. Añadirlo aquí abriría
     * un agujero (entraría al dashboard sin pasar por /account/password).
     */
    private const EXEMPT_ROUTES = [
        self::PASSWORD_ROUTE,
        'app_logout',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Intercepta cada petición principal. Prioridad 6: por debajo del firewall
     * (8) para tener el token ya resuelto, y por debajo del expirador de sesión
     * por inactividad (7) para que ése gane si la sesión ha caducado.
     *
     * @param RequestEvent $event Petición en curso.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || $user->isPasswordSet()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        // Sin ruta resuelta = asset estático o 404: no interferimos. Las rutas
        // internas de Symfony (perfiler, web debug toolbar, _*) tampoco se
        // tocan, o el entorno dev quedaría inutilizable.
        if ($route === null || str_starts_with($route, '_') || in_array($route, self::EXEMPT_ROUTES, true)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate(self::PASSWORD_ROUTE))
        );
    }
}
