<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Expires an authenticated user's session after a period of inactivity,
 * deterministically and inside the application.
 *
 * Why PHP's `gc_maxlifetime` is not enough: the session-file garbage collector
 * is best-effort. It relies on the system's session cleanup cron (which does
 * not run inside the DDEV container) or on PHP's probabilistic GC (1/100 per
 * request, which on low internal traffic almost never fires). On top of that,
 * closing the browser drops the session cookie, but "restore tabs" revives it:
 * if the session file is still on disk (because the GC never swept it), the
 * user shows up logged in the next day. Checking idle time on every request
 * kills that case without depending on server infrastructure, the same way in
 * DDEV and on the production hosting.
 *
 * Only authenticated users are affected: anonymous visitors of the public blog
 * never carry the activity stamp and are never redirected.
 */
final class SessionIdleTimeoutSubscriber
{
    /** Session key holding the unix timestamp of the last seen request. */
    private const LAST_ACTIVITY_KEY = '_last_activity';

    /**
     * @param int $maxIdleTime maximum allowed inactivity in seconds before the
     *                         session is invalidated
     */
    public function __construct(
        private readonly int $maxIdleTime,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Checks inactivity at the start of every main request. Priority 7 keeps it
     * just below the firewall (8) so the security token is already resolved and
     * we can tell authenticated users apart from anonymous ones.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null || !$token->getUser() instanceof UserInterface) {
            return; // anonymous: nothing to expire.
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();

        $now = time();
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if ($lastActivity !== null && ($now - $lastActivity) > $this->maxIdleTime) {
            $session->invalidate();
            $this->tokenStorage->setToken(null);
            $session->getFlashBag()->add(
                'warning',
                'Tu sesión se ha cerrado por inactividad. Vuelve a entrar.'
            );
            $event->setResponse(
                new RedirectResponse($this->urlGenerator->generate('app_login'))
            );

            return;
        }

        $session->set(self::LAST_ACTIVITY_KEY, $now);
    }
}
