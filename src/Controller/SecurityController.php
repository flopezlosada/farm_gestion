<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Security\MagicLinkMailer;
use App\Security\PartnerUserProvisioner;
use App\Security\UserChecker;
use App\Service\AppSettings;
use App\Validator\StrongPassword;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
     * Pantalla bloqueante para (re)establecer la contraseña. Sirve a CUALQUIER
     * cuenta autenticada —socix o gestión—, a diferencia de la antigua
     * /panel/setup que vivía bajo /panel y solo alcanzaba a socixs.
     *
     * Se llega aquí de dos formas, ambas señaladas por User::isPasswordSet() ==
     * false y reconducidas por {@see \App\EventSubscriber\ForcePasswordChangeSubscriber}:
     *   - primer acceso por magic-link (la cuenta nace sin contraseña real),
     *   - forzado manual por la administración (botón "obligar a cambiar").
     *
     * Quien entra por Google nunca aterriza aquí: el SSO marca passwordSet=true
     * (ver PartnerUserProvisioner), así que su credencial es Google, no una
     * contraseña.
     */
    #[Route('/account/password', name: 'app_account_password', methods: ['GET', 'POST'])]
    public function accountPassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Ya tiene contraseña: la pantalla no aplica (evita que alguien la abra
        // a mano para reescribir su contraseña sin pasar por el perfil, que sí
        // pide la actual).
        if ($user->isPasswordSet()) {
            return $this->redirectToRoute('app_post_login');
        }

        $form = $this->createFormBuilder()
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Las contraseñas no coinciden.',
                'required' => true,
                'first_options' => ['label' => 'Nueva contraseña'],
                'second_options' => ['label' => 'Repite la contraseña'],
                'constraints' => [new StrongPassword()],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Guardar y continuar'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setPasswordSet(true);
            $em->flush();

            $this->addFlash('notice', 'Contraseña configurada. Ya puedes seguir usando la aplicación.');

            return $this->redirectToRoute('app_post_login');
        }

        return $this->render('Security/set_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Primer acceso de un socix sin User aún. El socix mete email y celular;
     * si la pareja coincide con un Partner registrado, se crea (o reutiliza)
     * el User vinculado y se le envía el magic-link al correo.
     *
     * Antifuga: el flujo visual es siempre el mismo, encuentre o no. Así no
     * se puede inventariar qué emails están registrados como socixs.
     *
     * Ese mismo silencio deja a la administración sin saber por qué un socix
     * "pide el enlace y no le llega", así que cada salida sin envío deja una
     * traza en el canal `access` (fichero propio, ver config/packages/monolog.yaml).
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
        #[Autowire(service: 'monolog.logger.access')]
        LoggerInterface $accessLogger,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('Security/first_access.html.twig');
        }

        if (!$this->isCsrfTokenValid('login_link_first', (string) $request->request->get('_csrf_token'))) {
            $accessLogger->warning('Primer acceso: token CSRF inválido, no se envía enlace.');

            return $this->redirectToRoute('app_login_link_sent');
        }

        // Se leen antes que los guards para que las trazas de abandono puedan
        // decir de quién se trata. La validación de formato sigue más abajo.
        $email = trim((string) $request->request->get('email', ''));
        $phoneRaw = trim((string) $request->request->get('phone', ''));

        // El primer acceso es exclusivo de socixs. Con el acceso de socixs cerrado
        // por configuración (FEATURE_PARTNER_LOGIN), no tiene sentido enviar un
        // magic-link que el UserChecker rechazaría: seguimos el camino antifuga
        // (redirige a "enviado" sin mandar nada), igual que un email no registrado.
        if (!$settings->getBool(AppSettings::FEATURE_PARTNER_LOGIN)) {
            $accessLogger->info(
                'Primer acceso: no se envía enlace, el acceso de socixs está cerrado por configuración.',
                ['email' => $email]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

        // Antifuga: si se rebasa el límite seguimos redirigiendo a /login/sent
        // para no diferenciar el caso "límite excedido" del éxito.
        if (!$magicLinkLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            $accessLogger->warning(
                'Primer acceso: no se envía enlace, esta IP ha agotado el límite de solicitudes por hora.',
                ['email' => $email, 'ip' => $request->getClientIp()]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

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
            $accessLogger->info(
                'Primer acceso: el formulario no valida, no se busca socix.',
                ['email' => $email, 'campos' => array_keys($errors)]
            );

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

        if ($partner === null) {
            $accessLogger->info(
                'Primer acceso: sin coincidencia, no se envía enlace.',
                ['email' => $email, 'motivo' => $this->diagnoseLookupFailure($partnerRepository, $email)]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

        // Con el alta de usuarixs cerrada por configuración, resolveOrCreate
        // devuelve null para quien no tenga cuenta: el flujo sigue el camino
        // antifuga (redirige a /login/sent sin enviar nada), igual que un
        // email no registrado.
        $user = $provisioner->resolveOrCreate($partner);
        if ($user === null) {
            $accessLogger->info(
                'Primer acceso: socix identificado pero sin cuenta que usar (sin email en ficha, o alta de usuarixs cerrada).',
                ['email' => $email, 'partner_id' => $partner->getId()]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

        $magicLinkMailer->send($user);

        return $this->redirectToRoute('app_login_link_sent');
    }

    /**
     * Explica por qué el par email+teléfono no encontró socix, para la traza de
     * diagnóstico. Distinguir "ese email no está" de "el teléfono no cuadra" es
     * justo lo que ahorra la reproducción del caso, y sale de una consulta que
     * sólo se hace en el camino de fallo.
     *
     * @param PartnerRepository $partnerRepository Repositorio de socixs.
     * @param string            $email            Email tal cual lo tecleó quien lo intenta.
     *
     * @return string Motivo legible para el log.
     */
    private function diagnoseLookupFailure(PartnerRepository $partnerRepository, string $email): string
    {
        $conEseEmail = (int) $partnerRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('LOWER(p.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        return $conEseEmail > 0
            ? sprintf('hay %d socix(s) con ese email, pero el teléfono no coincide con el de la ficha', $conEseEmail)
            : 'ningún socix tiene ese email en su ficha';
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
        #[Autowire(service: 'monolog.logger.access')]
        LoggerInterface $accessLogger,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('Security/forgot.html.twig');
        }

        if (!$this->isCsrfTokenValid('login_link_forgot', (string) $request->request->get('_csrf_token'))) {
            $accessLogger->warning('Recuperar acceso: token CSRF inválido, no se envía enlace.');

            return $this->redirectToRoute('app_login_link_sent');
        }

        $email = trim((string) $request->request->get('email', ''));

        if (!$magicLinkLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            $accessLogger->warning(
                'Recuperar acceso: no se envía enlace, esta IP ha agotado el límite de solicitudes por hora.',
                ['email' => $email, 'ip' => $request->getClientIp()]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Indica tu email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no tiene un formato válido.';
        }

        if (!empty($errors)) {
            $accessLogger->info(
                'Recuperar acceso: el email tecleado no tiene formato válido.',
                ['email' => $email]
            );

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
        if ($user === null) {
            $accessLogger->info(
                'Recuperar acceso: no hay ninguna cuenta con ese email, no se envía enlace.',
                ['email' => $email]
            );

            return $this->redirectToRoute('app_login_link_sent');
        }

        try {
            $userChecker->checkPreAuth($user);
            $magicLinkMailer->send($user);
        } catch (AccountStatusException $e) {
            // No puede entrar: no enviamos enlace. Antifuga intacto.
            $accessLogger->info(
                'Recuperar acceso: la cuenta existe pero no puede entrar ahora, no se envía enlace.',
                ['email' => $email, 'motivo' => $e->getMessage()]
            );
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
