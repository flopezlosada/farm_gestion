<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Arranque y callback del SSO con Google. La resolución del usuario y el
 * login los hace {@see \App\Security\GoogleAuthenticator}.
 */
class GoogleController extends AbstractController
{
    /**
     * Arranca el flujo OAuth: redirige a Google pidiendo los scopes mínimos
     * (email + perfil básico).
     */
    #[Route('/connect/google', name: 'connect_google_start', methods: ['GET'])]
    public function connect(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile'], []);
    }

    /**
     * Callback de Google. La ruta la intercepta GoogleAuthenticator; este
     * método sólo se ejecutaría ante un misconfig en security.yaml.
     */
    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function check(): Response
    {
        throw new \LogicException('Ruta interceptada por GoogleAuthenticator. Si la ves, hay un misconfig en security.yaml.');
    }
}
