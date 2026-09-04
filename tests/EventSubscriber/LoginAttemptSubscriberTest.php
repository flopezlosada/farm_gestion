<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LoginAttemptSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\LoginLink\Exception\ExpiredLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkAuthenticationException;

/**
 * Traza de los intentos de acceso fallidos. Lo que se comprueba aquí es que el
 * motivo registrado sea el REAL: el AuthenticatorManager envuelve las
 * excepciones sensibles en una BadCredentialsException genérica (para no
 * filtrar qué cuentas existen) y deja la original como `previous`, de modo que
 * un listener ingenuo apuntaría "contraseña incorrecta" en todos los casos y el
 * log no serviría para atender a un socix que no puede entrar.
 */
class LoginAttemptSubscriberTest extends TestCase
{
    public function testDistingueLaCuentaInexistenteDeLaContrasenaIncorrecta(): void
    {
        $motivo = $this->motivoRegistrado(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException())
        );

        $this->assertSame('no hay ninguna cuenta con ese identificador', $motivo);
    }

    public function testLaContrasenaIncorrectaSeRegistraComoTal(): void
    {
        $motivo = $this->motivoRegistrado(new BadCredentialsException('Bad credentials.'));

        $this->assertSame('contraseña incorrecta', $motivo);
    }

    /**
     * El magic-link caducado llega envuelto: el authenticator lanza su propia
     * excepción y deja la de caducidad —que ni siquiera es de autenticación—
     * como previous. Es exactamente el caso de "he pinchado el enlace del
     * correo y me echa".
     */
    public function testElEnlaceCaducadoSeDistingueDeLasCredenciales(): void
    {
        $motivo = $this->motivoRegistrado(
            new InvalidLoginLinkAuthenticationException('Login link could not be validated.', 0, new ExpiredLoginLinkException())
        );

        $this->assertSame('el enlace de acceso ha caducado', $motivo);
    }

    public function testElEnlaceRotoSeRegistraComoNoValido(): void
    {
        $motivo = $this->motivoRegistrado(new InvalidLoginLinkAuthenticationException('Missing user from link.'));

        $this->assertSame('enlace de acceso no válido', $motivo);
    }

    /**
     * La cuenta bloqueada llega con el mensaje que escribe UserChecker, y ese
     * mensaje es justo lo que hay que leer en el log.
     */
    public function testLaCuentaBloqueadaRegistraElMensajeDelChecker(): void
    {
        $motivo = $this->motivoRegistrado(
            new CustomUserMessageAccountStatusException('Tu cuenta está bloqueada.')
        );

        $this->assertSame('Tu cuenta está bloqueada.', $motivo);
    }

    /**
     * El identificador tecleado se registra siempre que el authenticator llegó
     * a construir el passport: sin él, el log no dice a quién atender.
     */
    public function testRegistraElIdentificadorIntentado(): void
    {
        $capturado = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(function (string $mensaje, array $contexto) use (&$capturado): void {
                $capturado = $contexto;
            });

        (new LoginAttemptSubscriber($logger))->onLoginFailure(
            $this->failureEvent(new BadCredentialsException('Bad credentials.'))
        );

        $this->assertSame('socia@example.org', $capturado['identificador']);
    }

    /**
     * Lanza el listener con la excepción dada y devuelve el motivo que dejó
     * escrito en el log.
     *
     * @param \Throwable&\Symfony\Component\Security\Core\Exception\AuthenticationException $exception
     */
    private function motivoRegistrado($exception): string
    {
        $capturado = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $mensaje, array $contexto) use (&$capturado): void {
                $capturado = $contexto;
            });

        (new LoginAttemptSubscriber($logger))->onLoginFailure($this->failureEvent($exception));

        return $capturado['motivo'];
    }

    /**
     * Evento de fallo equivalente al que dispara el AuthenticatorManager, con
     * un passport que ya conoce el identificador tecleado.
     */
    private function failureEvent($exception): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            $this->createMock(AuthenticatorInterface::class),
            Request::create('/login'),
            null,
            'main',
            new SelfValidatingPassport(new UserBadge('socia@example.org', static fn () => null)),
        );
    }
}
