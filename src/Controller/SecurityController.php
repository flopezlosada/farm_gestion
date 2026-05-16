<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('Security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Recibe el email de la socia, genera un magic-link (válido 30 min, un
     * solo uso) y lo envía por correo. Para no filtrar qué emails están
     * registrados, siempre redirige a la misma pantalla "te hemos enviado
     * un correo": si el email no existe o el User no tiene email registrado,
     * tampoco se envía nada, pero la pantalla es la misma.
     */
    #[Route('/login/magic', name: 'app_login_link_request', methods: ['POST'])]
    public function requestMagicLink(
        Request $request,
        UserRepository $userRepository,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
    ): Response {
        $email = trim((string) $request->request->get('email', ''));

        if ($email !== '') {
            $user = $userRepository->findOneBy(['email' => $email]);
            if ($user !== null && $user->getEmail() !== null) {
                $details = $loginLinkHandler->createLoginLink($user);

                $message = (new TemplatedEmail())
                    ->to($user->getEmail())
                    ->subject('Tu enlace de acceso a la CSA Vega de Jarama')
                    ->htmlTemplate('email/magic_link.html.twig')
                    ->textTemplate('email/magic_link.txt.twig')
                    ->context([
                        'link' => $details->getUrl(),
                        'expires_at' => $details->getExpiresAt(),
                    ]);
                $mailer->send($message);
            }
        }

        return $this->redirectToRoute('app_login_link_sent');
    }

    #[Route('/login/magic/enviado', name: 'app_login_link_sent', methods: ['GET'])]
    public function magicLinkSent(): Response
    {
        return $this->render('Security/magic_sent.html.twig');
    }

    /**
     * Esta ruta la intercepta el firewall (login_link.check_route). El
     * método nunca se ejecuta: si llegas hasta aquí, hay un misconfig.
     */
    #[Route('/login/magic/check', name: 'app_login_link_check')]
    public function loginLinkCheck(): never
    {
        throw new \LogicException('Ruta interceptada por el firewall de login_link. Si la ves, hay un misconfig en security.yaml.');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Esta ruta la intercepta el firewall de logout. Si la ves, hay un misconfig en security.yaml.');
    }
}
