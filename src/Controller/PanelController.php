<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Form\ChangePasswordType;
use App\Form\PartnerProfileType;
use App\Repository\WeeklyBasketGroupRepository;
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Panel del socix — vista personal de cada socia/o.
 *
 * Requiere ROLE_PARTNER (asignado en User.roles) y un Partner vinculado
 * a User.partner. Si falta el vínculo, el panel redirige al admin (o a
 * la home pública) en lugar de explotar.
 */
#[Route('/panel')]
#[IsGranted('ROLE_PARTNER')]
class PanelController extends AbstractController
{
    #[Route('', name: 'panel', methods: ['GET'])]
    public function index(): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();

        return $this->render('Panel/index.html.twig', [
            'partner' => $partner,
            'active_share' => $this->activeShare($partner),
        ]);
    }

    #[Route('/perfil', name: 'panel_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $user = $this->getUser();
        $partner = $user->getPartner();

        $profileForm = $this->createForm(PartnerProfileType::class, $partner);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $em->flush();
            $this->addFlash('notice', 'Tus datos se han guardado.');
            return $this->redirectToRoute('panel_profile');
        }

        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $current = $passwordForm->get('currentPassword')->getData();
            if (!$hasher->isPasswordValid($user, $current)) {
                $passwordForm->get('currentPassword')->addError(
                    new \Symfony\Component\Form\FormError('La contraseña actual no es correcta.')
                );
            } else {
                $user->setPassword($hasher->hashPassword($user, $passwordForm->get('newPassword')->getData()));
                $em->flush();
                $this->addFlash('notice', 'Tu contraseña se ha actualizado.');
                return $this->redirectToRoute('panel_profile');
            }
        }

        return $this->render('Panel/profile.html.twig', [
            'partner' => $partner,
            'profile_form' => $profileForm->createView(),
            'password_form' => $passwordForm->createView(),
        ]);
    }

    /**
     * Deadline para acciones autoservicio sobre la próxima cesta. El
     * socix puede saltar la cesta o cambiar de nodo de recogida hasta
     * esta hora del miércoles previo al viernes de reparto, en hora
     * local (Europe/Madrid). Pasada esa hora, las acciones se bloquean
     * y se le pide contactar con la administración.
     *
     * Valor temporal hardcoded — pendiente de parametrizar cuando la
     * asociación decida la regla definitiva.
     */
    private const PICKUP_DEADLINE_WEEKDAY = 3;   // ISO 8601: 3 = miércoles
    private const PICKUP_DEADLINE_HOUR = 23;
    private const PICKUP_DEADLINE_MINUTE = 59;

    #[Route('/cesta', name: 'panel_basket', methods: ['GET'])]
    public function basket(WeeklyBasketRepository $weeklyBasketRepository, WeeklyBasketGroupRepository $weeklyBasketGroupRepository): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);

        return $this->render('Panel/basket.html.twig', [
            'partner' => $partner,
            'active_share' => $this->activeShare($partner),
            'group' => $partner->getWeeklyBasketGroup(),
            'next_basket' => $next,
            'next_basket_skipped' => $next?->getWeeklyBasketStatus()?->getId() === 2,
            'next_basket_group' => $next?->getWeeklyBasketGroup(),
            'can_change_next' => $next !== null && $this->isWithinPickupDeadline($next),
            'pickup_deadline' => $next !== null ? $this->pickupDeadlineFor($next) : null,
            'pickup_groups' => $weeklyBasketGroupRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Saltar / volver a recoger la próxima cesta. Acción toggle (status
     * 1 ↔ 2) limitada al deadline previo al viernes. Cada toggle deja
     * un PartnerEvent en el feed del socix para que admin lo vea en el
     * resumen periódico.
     */
    #[Route('/cesta/skip-toggle', name: 'panel_basket_skip_toggle', methods: ['POST'])]
    public function skipNextBasket(
        Request $request,
        WeeklyBasketRepository $weeklyBasketRepository,
        WeeklyBasketStatusRepository $weeklyBasketStatusRepository,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel_basket');
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);

        if ($next === null) {
            $this->addFlash('warning', 'No tienes una próxima cesta sobre la que actuar.');
            return $this->redirectToRoute('panel_basket');
        }

        if (!$this->isWithinPickupDeadline($next)) {
            $this->addFlash('error', 'Ya no se puede cambiar la cesta de esta semana — el plazo terminó. Si necesitas avisar, contacta con la administración.');
            return $this->redirectToRoute('panel_basket');
        }

        $currentStatus = $next->getWeeklyBasketStatus();
        $currentId = $currentStatus?->getId();

        // Status 1 = "Recoge", 2 = "No la recoge". El toggle solo opera
        // entre estos dos; cualquier otro estado lo deja como está y
        // avisa al socix (caso defensivo, no debería ocurrir en panel).
        if ($currentId === 1) {
            $next->setWeeklyBasketStatus($weeklyBasketStatusRepository->find(2));
            $em->persist(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_SKIP));
            $this->addFlash('notice', 'Listo: hemos marcado que esta semana no recogerás la cesta. Si cambias de opinión, puedes deshacerlo desde aquí antes del plazo.');
        } elseif ($currentId === 2) {
            $next->setWeeklyBasketStatus($weeklyBasketStatusRepository->find(1));
            $em->persist(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_UNSKIP));
            $this->addFlash('notice', 'Listo: al final sí recogerás la cesta esta semana.');
        } else {
            $this->addFlash('warning', 'Tu cesta está en un estado especial y no se puede cambiar desde aquí. Contacta con la administración si necesitas modificarla.');
        }

        $em->flush();
        return $this->redirectToRoute('panel_basket');
    }

    /**
     * Cambio puntual de nodo de recogida para la próxima cesta. Toca
     * solo WeeklyBasket.weekly_basket_group (que es histórico por
     * diseño), NO Partner.weekly_basket_group (que es el por defecto).
     */
    #[Route('/cesta/change-group', name: 'panel_basket_change_group', methods: ['POST'])]
    public function changePickupGroup(
        Request $request,
        WeeklyBasketRepository $weeklyBasketRepository,
        WeeklyBasketGroupRepository $weeklyBasketGroupRepository,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_change_group', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel_basket');
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);

        if ($next === null) {
            $this->addFlash('warning', 'No tienes una próxima cesta sobre la que actuar.');
            return $this->redirectToRoute('panel_basket');
        }

        if (!$this->isWithinPickupDeadline($next)) {
            $this->addFlash('error', 'Ya no se puede cambiar de nodo para esta semana — el plazo terminó. Contacta con la administración si necesitas avisar.');
            return $this->redirectToRoute('panel_basket');
        }

        $groupId = (int) $request->request->get('group_id', 0);
        $group = $groupId > 0 ? $weeklyBasketGroupRepository->find($groupId) : null;
        if ($group === null) {
            $this->addFlash('error', 'Selecciona un grupo de recogida válido.');
            return $this->redirectToRoute('panel_basket');
        }

        $previousGroup = $next->getWeeklyBasketGroup();
        if ($previousGroup !== null && $previousGroup->getId() === $group->getId()) {
            $this->addFlash('warning', 'Ya recoges la cesta en ese grupo esta semana.');
            return $this->redirectToRoute('panel_basket');
        }

        $next->setWeeklyBasketGroup($group);

        $event = new PartnerEvent($partner, PartnerEvent::TYPE_NODE_CHANGE);
        $event->setPayload([
            'scope' => 'one_off',
            'basket_id' => $next->getBasket()?->getId(),
            'from_group_id' => $previousGroup?->getId(),
            'from_group_name' => $previousGroup?->getName(),
            'to_group_id' => $group->getId(),
            'to_group_name' => $group->getName(),
        ]);
        $em->persist($event);
        $em->flush();

        $this->addFlash('notice', sprintf('Listo: esta semana recogerás la cesta en "%s". El cambio aplica solo a este reparto.', $group->getName()));
        return $this->redirectToRoute('panel_basket');
    }

    /**
     * El deadline para tocar una WeeklyBasket concreta es el miércoles
     * 23:59 (zona horaria local) anterior al viernes de reparto del
     * Basket asociado.
     */
    private function pickupDeadlineFor(WeeklyBasket $weeklyBasket): ?\DateTimeImmutable
    {
        $basketDate = $weeklyBasket->getBasket()?->getDate();
        if ($basketDate === null) {
            return null;
        }

        // Basket.date es \DateTime mutable; clonamos como immutable para
        // operar con seguridad sin pisar el original.
        $pickup = \DateTimeImmutable::createFromMutable($basketDate);

        // ISO weekday del viernes habitual = 5. Para llegar al miércoles
        // restamos (viernes - miércoles) días = 2. Si por excepción de
        // calendario el reparto cae en otro día, garantizamos que el
        // deadline siempre se sitúa antes con abs().
        $diffDays = abs((int) $pickup->format('N') - self::PICKUP_DEADLINE_WEEKDAY);

        return $pickup
            ->setTime(self::PICKUP_DEADLINE_HOUR, self::PICKUP_DEADLINE_MINUTE, 0)
            ->modify(sprintf('-%d days', $diffDays));
    }

    private function isWithinPickupDeadline(WeeklyBasket $weeklyBasket): bool
    {
        $deadline = $this->pickupDeadlineFor($weeklyBasket);
        if ($deadline === null) {
            return false;
        }
        return new \DateTimeImmutable('now') < $deadline;
    }

    #[Route('/familia', name: 'panel_family', methods: ['GET'])]
    public function family(): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        // Inferencia de familia: por ahora dejamos placeholder.
        // El modelo actual no tiene una entidad explícita de "familia" y la
        // inferencia desde PartnerBasketShare necesita validación con admin.
        // Cuando se aclare, se rellena esta vista.
        return $this->render('Panel/family.html.twig', [
            'partner' => $this->getUser()->getPartner(),
            'family_members' => [],
        ]);
    }

    /**
     * Pantalla bloqueante tras el primer magic-link: el User aterriza aquí
     * para elegir su contraseña permanente antes de poder usar el panel.
     * Si ya tiene contraseña configurada, redirige al panel directamente
     * (la página deja de tener sentido).
     */
    #[Route('/setup', name: 'panel_setup', methods: ['GET', 'POST'])]
    public function setup(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $this->getUser();
        if ($user->isPasswordSet()) {
            return $this->redirectToRoute('panel');
        }

        $form = $this->createFormBuilder()
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Las contraseñas no coinciden.',
                'required' => true,
                'first_options' => ['label' => 'Nueva contraseña'],
                'second_options' => ['label' => 'Repite la contraseña'],
                'constraints' => [
                    new NotBlank(message: 'Indica una contraseña.'),
                    new Length(min: 8, minMessage: 'La contraseña debe tener al menos {{ limit }} caracteres.'),
                ],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Guardar y entrar'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setPasswordSet(true);
            $em->flush();

            $this->addFlash('notice', 'Contraseña configurada. Ya puedes entrar con tu email y contraseña la próxima vez.');
            return $this->redirectToRoute('panel');
        }

        return $this->render('Panel/setup.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Gating común a todas las acciones del panel:
     *   - User aún no ha configurado su contraseña → /panel/setup.
     *   - User sin Partner vinculado → fuera del panel.
     *
     * Devuelve null si todo está bien y el controller puede seguir.
     * No se aplica a la acción setup (que justamente atiende el primer caso).
     */
    private function ensureReady(): ?RedirectResponse
    {
        $user = $this->getUser();

        if ($user !== null && method_exists($user, 'isPasswordSet') && !$user->isPasswordSet()) {
            return $this->redirectToRoute('panel_setup');
        }

        if ($user && method_exists($user, 'getPartner') && $user->getPartner() !== null) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a admin que te vincule para usar el panel.');

        foreach (['ROLE_ADMIN', 'ROLE_GESTION_GRANJA', 'ROLE_GESTION_SOCIXS', 'ROLE_GESTION_CESTAS', 'ROLE_BLOG'] as $role) {
            if ($this->isGranted($role)) {
                return $this->redirectToRoute('dashboard');
            }
        }
        return $this->redirectToRoute('homepage');
    }

    /**
     * PartnerBasketShare activo del socix: el más reciente que aún no ha
     * sido cerrado (end_date NULL o futura). Devuelve null si no tiene.
     */
    private function activeShare(Partner $partner): ?PartnerBasketShare
    {
        $today = new \DateTime('today');
        $active = null;
        foreach ($partner->getPartnerBasketShares() as $share) {
            $end = $share->getEndDate();
            if ($end === null || $end >= $today) {
                if ($active === null || $share->getStartDate() > $active->getStartDate()) {
                    $active = $share;
                }
            }
        }
        return $active;
    }
}
