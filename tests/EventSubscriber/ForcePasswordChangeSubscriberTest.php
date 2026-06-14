<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\ForcePasswordChangeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Tests del guard global que obliga a (re)establecer contraseña. Verifica que
 * reconduce a la pantalla de contraseña a quien tiene passwordSet = false, y
 * que NO interfiere con anónimos, cuentas ya configuradas, las rutas exentas
 * (la propia pantalla y el logout), las rutas internas de Symfony ni los
 * assets sin ruta resuelta.
 */
class ForcePasswordChangeSubscriberTest extends TestCase
{
    private const PASSWORD_URL = '/account/password';

    /**
     * Un visitante anónimo (sin token) nunca se redirige: el frontend público
     * del blog debe seguir abierto.
     */
    public function testDoesNothingForAnonymousVisitor(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $event = $this->makeRequestEvent('dashboard');
        $this->makeSubscriber($tokenStorage)->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * Cuenta con la contraseña ya establecida: no se toca.
     */
    public function testDoesNothingWhenPasswordIsSet(): void
    {
        $user = (new User())->setPasswordSet(true);

        $event = $this->makeRequestEvent('dashboard');
        $this->makeSubscriber($this->tokenStorageFor($user))->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * Cuenta pendiente (passwordSet = false) navegando a una ruta normal: se
     * corta la petición y se redirige a la pantalla de contraseña.
     */
    public function testRedirectsWhenPasswordNotSet(): void
    {
        $user = (new User())->setPasswordSet(false);

        $event = $this->makeRequestEvent('dashboard');
        $this->makeSubscriber($this->tokenStorageFor($user))->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::PASSWORD_URL, $response->getTargetUrl());
    }

    /**
     * En las rutas exentas (la propia pantalla de contraseña y el logout) no se
     * redirige: si no, sería un bucle infinito o no se podría salir.
     *
     * @dataProvider exemptRoutes
     */
    public function testDoesNotRedirectOnExemptRoutes(string $route): void
    {
        $user = (new User())->setPasswordSet(false);

        $event = $this->makeRequestEvent($route);
        $this->makeSubscriber($this->tokenStorageFor($user))->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function exemptRoutes(): array
    {
        return [
            'pantalla de contraseña' => ['app_account_password'],
            'logout' => ['app_logout'],
        ];
    }

    /**
     * Las rutas internas de Symfony (web debug toolbar, profiler, _*) no se
     * tocan, o el entorno dev quedaría inutilizable.
     */
    public function testDoesNotRedirectOnInternalRoutes(): void
    {
        $user = (new User())->setPasswordSet(false);

        $event = $this->makeRequestEvent('_wdt');
        $this->makeSubscriber($this->tokenStorageFor($user))->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * Peticiones sin ruta resuelta (assets estáticos, 404): no interferimos,
     * para no romper el CSS/JS de la propia pantalla de contraseña.
     */
    public function testDoesNotRedirectWithoutResolvedRoute(): void
    {
        $user = (new User())->setPasswordSet(false);

        $event = $this->makeRequestEvent(null);
        $this->makeSubscriber($this->tokenStorageFor($user))->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    /**
     * Un token cuyo usuario no es nuestra entidad User (otra implementación de
     * UserInterface) se ignora: el guard solo entiende App\Entity\User.
     */
    public function testIgnoresNonAppUser(): void
    {
        $tokenStorage = $this->tokenStorageFor($this->createMock(UserInterface::class));

        $event = $this->makeRequestEvent('dashboard');
        $this->makeSubscriber($tokenStorage)->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    private function makeSubscriber(TokenStorageInterface $tokenStorage): ForcePasswordChangeSubscriber
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn(self::PASSWORD_URL);

        return new ForcePasswordChangeSubscriber($tokenStorage, $urlGenerator);
    }

    private function tokenStorageFor(UserInterface $user): TokenStorageInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }

    /**
     * Construye un RequestEvent principal con (opcionalmente) una ruta resuelta
     * en los atributos, como haría el RouterListener antes de este guard.
     */
    private function makeRequestEvent(?string $route): RequestEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
