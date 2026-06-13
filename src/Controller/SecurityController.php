<?php

namespace App\Controller;

use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Security\MagicLinkMailer;
use App\Security\PartnerUserProvisioner;
use App\Security\UserChecker;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_post_login');
        }

        return $this->render('Security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Dispatcher tras el login. Si el User tiene cualquier rol de
     * gestión va al dashboard de gestión; si solo tiene ROLE_PARTNER
     * va al panel. Solución al 403 que sufría un socix recién logueado
     * cuando el default_target_path apuntaba a /gestion/dashboard sin
     * que tuviera permisos sobre /gestion.
     */
    #[Route('/post-login', name: 'app_post_login')]
    public function postLogin(): Response
    {
        $managementRoles = ['ROLE_ADMIN', 'ROLE_GESTION_GRANJA', 'ROLE_GESTION_SOCIXS', 'ROLE_GESTION_REPARTO', 'ROLE_BLOG'];
        foreach ($managementRoles as $role) {
            if ($this->isGranted($role)) {
                return $this->redirectToRoute('dashboard');
            }
        }
        return $this->redirectToRoute('panel');
    }

    /**
     * Primer acceso de un socix sin User aún. El socix mete email y celular;
     * si la pareja coincide con un Partner registrado, se crea (o reutiliza)
     * el User vinculado y se le envía el magic-link al correo.
     *
     * Antifuga: el flujo visual es siempre el mismo, encuentre o no. Así no
     * se puede inventariar qué emails están registrados como socixs.
     */
    #[Route('/login/first-access', name: 'app_login_link_first', methods: ['GET', 'POST'])]
    public function firstAccess(
        Request $request,
        PartnerRepository $partnerRepository,
        PartnerUserProvisioner $provisioner,
        MagicLinkMailer $magicLinkMailer,
        AppSettings $settings,
        #[Autowire(service: 'limiter.magic_link')]
        RateLimiterFactory $magicLinkLimiter,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('Security/first_access.html.twig');
        }

        if (!$this->isCsrfTokenValid('login_link_first', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('app_login_link_sent');
        }

        // El primer acceso es exclusivo de socixs. Con el acceso de socixs cerrado
        // por configuración (FEATURE_PARTNER_LOGIN), no tiene sentido enviar un
        // magic-link que el UserChecker rechazaría: seguimos el camino antifuga
        // (redirige a "enviado" sin mandar nada), igual que un email no registrado.
        if (!$settings->getBool(AppSettings::FEATURE_PARTNER_LOGIN)) {
            return $this->redirectToRoute('app_login_link_sent');
        }

        // Antifuga: si se rebasa el límite seguimos redirigiendo a /login/sent
        // para no diferenciar el caso "límite excedido" del éxito.
        if (!$magicLinkLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->redirectToRoute('app_login_link_sent');
        }

        $email = trim((string) $request->request->get('email', ''));
        $phoneRaw = trim((string) $request->request->get('phone', ''));

        // Validamos formato antes de buscar. Errores de formato sí se enseñan
        // — son reglas públicas, no leak de qué emails están registrados.
        // El "no encontrado" sigue por el camino antifuga (redirige a sent).
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Indica tu email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no tiene un formato válido.';
        }

        $phone = null;
        if ($phoneRaw === '') {
            $errors['phone'] = 'Indica tu teléfono.';
        } else {
            $phone = $this->normalizePhone($phoneRaw);
            if ($phone === null) {
                $errors['phone'] = 'El teléfono debe tener 9 dígitos (puedes incluir prefijo internacional, espacios o guiones).';
            }
        }

        if (!empty($errors)) {
            return $this->render('Security/first_access.html.twig', [
                'errors' => $errors,
                'last_email' => $email,
                'last_phone' => $phoneRaw,
            ]);
        }

        // Lookup case-insensitive: el Partner pudo registrar su email en
        // cualquier capitalización y no queremos que el socix tenga que
        // recordar exactamente cómo se escribió.
        $partner = $partnerRepository->createQueryBuilder('p')
            ->where('LOWER(p.email) = LOWER(:email)')
            ->andWhere('p.celular = :celular')
            ->setParameter('email', $email)
            ->setParameter('celular', $phone)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        // Con el alta de usuarixs cerrada por configuración, resolveOrCreate
        // devuelve null para quien no tenga cuenta: el flujo sigue el camino
        // antifuga (redirige a /login/sent sin enviar nada), igual que un
        // email no registrado.
        if ($partner !== null) {
            $user = $provisioner->resolveOrCreate($partner);
            if ($user !== null) {
                $magicLinkMailer->send($user);
            }
        }

        return $this->redirectToRoute('app_login_link_sent');
    }

    /**
     * Recuperación de acceso para User existente: pide email, manda link.
     * Para Partners sin User vinculado este flujo no aplica — esos pasan
     * por /login/first-access.
     */
    #[Route('/login/forgot', name: 'app_login_link_forgot', methods: ['GET', 'POST'])]
    public function forgot(
        Request $request,
        UserRepository $userRepository,
        MagicLinkMailer $magicLinkMailer,
        UserChecker $userChecker,
        #[Autowire(service: 'limiter.magic_link')]
        RateLimiterFactory $magicLinkLimiter,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('Security/forgot.html.twig');
        }

        if (!$this->isCsrfTokenValid('login_link_forgot', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('app_login_link_sent');
        }

        if (!$magicLinkLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->redirectToRoute('app_login_link_sent');
        }

        $email = trim((string) $request->request->get('email', ''));

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Indica tu email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no tiene un formato válido.';
        }

        if (!empty($errors)) {
            return $this->render('Security/forgot.html.twig', [
                'errors' => $errors,
                'last_email' => $email,
            ]);
        }

        // Solo mandamos el enlace si la cuenta podría entrar AHORA: el UserChecker
        // es la fuente única de esa decisión (acceso de socixs cerrado por
        // configuración, o cuenta bloqueada). Si no, seguimos el camino antifuga
        // (redirige a "enviado" sin mandar nada), igual que un email no registrado.
        // No duplicamos aquí la lista de roles de equipo: la conoce el UserChecker.
        $user = $userRepository->loadUserByIdentifier($email);
        if ($user !== null) {
            try {
                $userChecker->checkPreAuth($user);
                $magicLinkMailer->send($user);
            } catch (AccountStatusException) {
                // No puede entrar: no enviamos enlace. Antifuga intacto.
            }
        }

        return $this->redirectToRoute('app_login_link_sent');
    }

    #[Route('/login/sent', name: 'app_login_link_sent', methods: ['GET'])]
    public function linkSent(): Response
    {
        return $this->render('Security/sent.html.twig');
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

    /**
     * Normaliza un teléfono español a 9 dígitos para comparar con
     * Partner.celular (INTEGER). Quita todo lo que no sea dígito y, si
     * sobran caracteres por delante (prefijo 34 o 0034), se queda con
     * los últimos 9. Devuelve null si no se obtienen 9 dígitos limpios.
     */
    private function normalizePhone(string $raw): ?int
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) > 9) {
            $digits = substr($digits, -9);
        }
        if (strlen($digits) !== 9) {
            return null;
        }
        return (int) $digits;
    }
}
