<?php

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticator del SSO con Google para socixs.
 *
 * Lazy provisioning condicionado: el email verificado que devuelve Google se
 * resuelve contra los socixs activos. Sólo se crea/enlaza el User si casa con
 * exactamente un Partner activo (ver
 * {@see PartnerUserProvisioner::resolveByVerifiedEmail()}); no se autoprovisiona
 * ninguna cuenta para un gmail que no esté en la BBDD.
 */
class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly PartnerUserProvisioner $provisioner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return 'connect_google_check' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                // Google entrega el email ya verificado con el scope `email`.
                $user = $this->provisioner->resolveByVerifiedEmail((string) $googleUser->getEmail());
                if (null === $user) {
                    throw new CustomUserMessageAuthenticationException(
                        'Esa cuenta de Google no está asociada a ningún socix. Usa el enlace por correo o contacta con la asociación.'
                    );
                }

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Mismo dispatcher que el login por formulario / magic-link: decide
        // destino (gestión vs panel) según los roles del User.
        return new RedirectResponse($this->urlGenerator->generate('app_post_login'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add(
            'error',
            strtr($exception->getMessageKey(), $exception->getMessageData())
        );

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
