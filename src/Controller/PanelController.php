<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Form\ChangePasswordType;
use App\Form\PartnerProfileType;
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

    #[Route('/cesta', name: 'panel_basket', methods: ['GET'])]
    public function basket(): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();

        return $this->render('Panel/basket.html.twig', [
            'partner' => $partner,
            'active_share' => $this->activeShare($partner),
            'group' => $partner->getWeeklyBasketGroup(),
        ]);
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
