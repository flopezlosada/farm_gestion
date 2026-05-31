<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SessionIdleTimeoutSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Tests del idle-timeout de sesión a nivel de aplicación. Mata el caso real
 * reportado (cookie de sesión revivida por "restaurar pestañas" + fichero de
 * sesión que el GC nunca barre) sin depender de infraestructura del servidor.
 */
class SessionIdleTimeoutSubscriberTest extends TestCase
{
    private const MAX_IDLE = 7200;

    /**
     * Un visitante anónimo (sin token de usuario) nunca se ve afectado: no se
     * redirige ni se toca su sesión. Cubre el frontend público del blog.
     */
    public function testDoesNothingForAnonymousVisitor(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $tokenStorage->expects($this->never())->method('setToken');

        $session = new Session(new MockArraySessionStorage());
        $session->set('_last_activity', time() - 99999); // aunque hubiera sello viejo

        $subscriber = new SessionIdleTimeoutSubscriber(
            self::MAX_IDLE,
            $tokenStorage,
            $this->createMock(UrlGeneratorInterface::class),
        );
        $event = $this->makeRequestEvent($session);

        $subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * Usuario autenticado con actividad reciente: no se desloguea y se refresca
     * el sello de última actividad a "ahora".
     */
    public function testStampsActivityForActiveUser(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_last_activity', time() - 60);

        $subscriber = new SessionIdleTimeoutSubscriber(
            self::MAX_IDLE,
            $this->authenticatedTokenStorage(),
            $this->createMock(UrlGeneratorInterface::class),
        );
        $event = $this->makeRequestEvent($session);

        $subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
        $this->assertGreaterThanOrEqual(time() - 1, $session->get('_last_activity'));
    }

    /**
     * Usuario autenticado inactivo más de 2h: la sesión se invalida, el token se
     * limpia y la request se corta con una redirección a login.
     */
    public function testExpiresIdleSessionAndRedirectsToLogin(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $tokenStorage->expects($this->once())->method('setToken')->with(null);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->with('app_login')->willReturn('/login');

        $session = new Session(new MockArraySessionStorage());
        $session->set('_last_activity', time() - (self::MAX_IDLE + 800)); // > 2h

        $subscriber = new SessionIdleTimeoutSubscriber(self::MAX_IDLE, $tokenStorage, $urlGenerator);
        $event = $this->makeRequestEvent($session);

        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
        $this->assertNull($session->get('_last_activity'), 'invalidate() debe limpiar el sello');
    }

    /**
     * Construye un RequestEvent principal con la sesión dada adjunta al request.
     */
    private function makeRequestEvent(Session $session): RequestEvent
    {
        $request = new Request();
        $request->setSession($session);

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /**
     * Token storage que devuelve un usuario autenticado cualquiera.
     */
    private function authenticatedTokenStorage(): TokenStorageInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(UserInterface::class));

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }
}
