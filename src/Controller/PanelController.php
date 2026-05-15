<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Form\ChangePasswordType;
use App\Form\PartnerProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        if (($redirect = $this->ensurePartnerOrRedirect()) !== null) {
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
        if (($redirect = $this->ensurePartnerOrRedirect()) !== null) {
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
        if (($redirect = $this->ensurePartnerOrRedirect()) !== null) {
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
        if (($redirect = $this->ensurePartnerOrRedirect()) !== null) {
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
     * Si el User logueado no tiene Partner vinculado, redirige donde tenga
     * sentido (dashboard admin si es gestor, home pública si no). Si todo
     * está bien, devuelve null y el controller sigue.
     */
    private function ensurePartnerOrRedirect(): ?RedirectResponse
    {
        $user = $this->getUser();
        if ($user && method_exists($user, 'getPartner') && $user->getPartner() !== null) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a admin que te vincule para usar el panel.');

        // Si tiene cualquier rol de gestión, mejor al dashboard que a la home pública.
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
