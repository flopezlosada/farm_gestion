<?php

namespace App\EventListener;

use App\Service\Accounting\Closing\ClosedPeriodException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cuando {@see ClosedPeriodGuard} para un guardado, quien lo intentó ve por qué y
 * vuelve a la pantalla de la que venía, en vez de un error 500. Así ningún controller
 * tiene que repetir la comprobación: la regla vive en un solo sitio.
 */
final class ClosedPeriodExceptionListener
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ClosedPeriodException) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $exception->getMessage());
        }

        // Sólo se vuelve a una página de esta misma aplicación.
        $referer = (string) $request->headers->get('referer');
        $back = str_starts_with($referer, $request->getSchemeAndHttpHost().'/')
            ? $referer
            : $this->urls->generate('accounting_index');

        $event->setResponse(new RedirectResponse($back));
    }
}
