<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\LoginLink\Exception\ExpiredLoginLinkException;
use Symfony\Component\Security\Http\LoginLink\Exception\InvalidLoginLinkAuthenticationException;

/**
 * Deja constancia de cada intento de entrar en la aplicación, entre y no entre.
 *
 * Escucha los eventos del sistema de autenticación en vez de instrumentar cada
 * vía por separado: todo fallo de cualquier authenticator —formulario,
 * magic-link o Google— acaba en un LoginFailureEvent, así que un único punto
 * cubre las tres. Escribe en el canal `access`, que en producción tiene su
 * propio fichero y no depende de que la petición termine en error.
 *
 * Existe porque el silencio del flujo de acceso es deliberado de cara a quien
 * lo usa (no se le dice si el email existe, para no permitir inventariar
 * socixs) y eso dejaba a la administración sin nada que mirar cuando alguien
 * llama diciendo "no puedo entrar".
 */
final class LoginAttemptSubscriber
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.access')]
        private readonly LoggerInterface $accessLogger,
    ) {
    }

    /**
     * Registra un intento fallido con la causa real y a nombre de quién iba.
     *
     * @param LoginFailureEvent $event Fallo de autenticación de cualquier authenticator.
     */
    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->accessLogger->warning('Acceso denegado.', [
            'identificador' => $this->identifierOf($event),
            'via' => $this->shortName($event->getAuthenticator()::class),
            'motivo' => $this->describeCause($event->getException()),
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }

    /**
     * Registra una entrada correcta. Sirve para distinguir "no le llegó el
     * enlace" de "entró y se perdió después".
     *
     * @param LoginSuccessEvent $event Autenticación resuelta con éxito.
     */
    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->accessLogger->info('Acceso concedido.', [
            'identificador' => $event->getUser()->getUserIdentifier(),
            'via' => $this->shortName($event->getAuthenticator()::class),
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }

    /**
     * Identificador que se intentó usar. Sale del passport cuando el
     * authenticator llegó a construirlo; si falló antes (CSRF inválido, por
     * ejemplo) se recurre al campo del formulario de login.
     *
     * @param LoginFailureEvent $event Fallo de autenticación.
     *
     * @return string Identificador tecleado, o una marca de que no se conoce.
     */
    private function identifierOf(LoginFailureEvent $event): string
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        if ($badge instanceof UserBadge) {
            return $badge->getUserIdentifier();
        }

        $tecleado = trim((string) $event->getRequest()->request->get('_username', ''));

        return $tecleado !== '' ? $tecleado : '(desconocido)';
    }

    /**
     * Traduce la excepción a una frase que se entienda leyendo el fichero.
     *
     * OJO con la envoltura: para no filtrar qué cuentas existen, el
     * AuthenticatorManager sustituye las excepciones sensibles (usuario
     * inexistente, cuenta con estado inválido) por una BadCredentialsException
     * genérica y guarda la original como `previous`. Sin mirar ahí, todos los
     * fallos parecerían "contraseña incorrecta".
     *
     * @param AuthenticationException $exception Excepción tal como llega en el evento.
     *
     * @return string Motivo legible.
     */
    private function describeCause(AuthenticationException $exception): string
    {
        $causa = $exception->getPrevious() instanceof AuthenticationException
            ? $exception->getPrevious()
            : $exception;

        return match (true) {
            $causa instanceof UserNotFoundException => 'no hay ninguna cuenta con ese identificador',
            $causa instanceof CustomUserMessageAccountStatusException => $causa->getMessage(),
            // El enlace caducado no llega como AuthenticationException: el
            // authenticator lo reempaqueta y guarda el original como previous,
            // que aquí es una RuntimeException corriente.
            $causa instanceof InvalidLoginLinkAuthenticationException => $causa->getPrevious() instanceof ExpiredLoginLinkException
                ? 'el enlace de acceso ha caducado'
                : 'enlace de acceso no válido',
            $causa instanceof TooManyLoginAttemptsAuthenticationException => 'demasiados intentos seguidos, cuenta frenada temporalmente',
            $causa instanceof InvalidCsrfTokenException => 'token CSRF inválido (formulario caducado o cookies bloqueadas)',
            $causa instanceof BadCredentialsException => 'contraseña incorrecta',
            default => sprintf('%s: %s', $this->shortName($causa::class), $causa->getMessage()),
        };
    }

    /**
     * Nombre de clase sin el namespace, para que la línea de log sea legible.
     *
     * @param string $fqcn Nombre de clase completo.
     *
     * @return string Última porción del nombre.
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
