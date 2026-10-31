<?php

namespace App\Controller;

use App\Entity\OfficialNotice;
use App\Entity\User;
use App\Repository\OfficialNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los avisos de notificación oficial que han llegado al buzón: los que nadie ha
 * mirado en la sede, y los últimos que sí, con quién y cuándo.
 *
 * Aquí no hay ningún enlace a la notificación, a propósito: se entra escribiendo a
 * mano la dirección de la sede, nunca desde el correo.
 */
#[Route('/gestion/accounting/official-notices')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class OfficialNoticeController extends AbstractController
{
    /**
     * Las pendientes y las últimas revisadas.
     *
     * @param OfficialNoticeRepository $notices Los avisos.
     *
     * @return Response La lista.
     */
    #[Route('/', name: 'accounting_official_notices', methods: ['GET'])]
    public function index(OfficialNoticeRepository $notices): Response
    {
        return $this->render('accounting/official_notices.html.twig', [
            'pending' => $notices->findUnreviewed(),
            'reviewed' => $notices->findRecentReviewed(),
        ]);
    }

    /**
     * Alguien ha entrado en la sede y la ha mirado: deja de salir en la portada y
     * queda quién y cuándo. Si otra persona se adelantó, se queda la suya.
     *
     * @param OfficialNotice         $notice  El aviso.
     * @param Request                $request Con el token del formulario.
     * @param EntityManagerInterface $em      Para guardar.
     *
     * @return Response Vuelta a la lista.
     */
    #[Route('/{id}/reviewed', name: 'accounting_official_notice_reviewed', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reviewed(OfficialNotice $notice, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('accounting_official_notice_reviewed_' . $notice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_official_notices');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$notice->markReviewed($user, new \DateTimeImmutable())) {
            $this->addFlash('info', sprintf('Ya la había marcado %s.', $notice->getReviewedBy()?->getDisplayName() ?? 'otra persona'));

            return $this->redirectToRoute('accounting_official_notices');
        }
        $em->flush();
        $this->addFlash('success', sprintf('Notificación de %s marcada como revisada en la sede.', $notice->getAgency()));

        return $this->redirectToRoute('accounting_official_notices');
    }
}
