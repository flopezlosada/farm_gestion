<?php

namespace App\Controller;

use App\Entity\DonationInitiative;
use App\Entity\FeeAdjustment;
use App\Entity\Partner;
use App\Entity\User;
use App\Form\FeeAdjustmentType;
use App\Http\QueryId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lo que un socix paga de más o de menos sobre la tarifa. Se ve en su ficha, en el
 * bloque «Cuota»; aquí se pone, se termina o se quita uno puesto por error.
 *
 * Todo es escritura de socixs: el permiso de edición (y el de la ruta, que lo exige
 * en todo POST bajo /gestion/partner).
 */
#[Route('/gestion/partner')]
#[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
class FeeAdjustmentController extends AbstractController
{
    /**
     * Poner un ajuste. Empieza por defecto el mes que viene, que es cuando suele
     * entrar un cambio de cuota; desde una iniciativa llega ya elegida.
     */
    #[Route('/{id}/fee-adjustments/new', name: 'fee_adjustment_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Request $request, Partner $partner, EntityManagerInterface $em): Response
    {
        $initiativeId = QueryId::from($request->query, 'initiative');
        $initiative = $initiativeId !== null ? $em->find(DonationInitiative::class, $initiativeId) : null;
        $form = $this->createForm(FeeAdjustmentType::class, [
            'direction' => $initiative !== null ? FeeAdjustmentType::MORE : null,
            'initiative' => $initiative?->isOpen() ? $initiative : null,
            'fromMonth' => FeeAdjustment::month(new \DateTimeImmutable('first day of next month')),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{direction: string, amount: string, reason: string, initiative: ?DonationInitiative, fromMonth: \DateTimeImmutable, untilMonth: ?\DateTimeImmutable} $data */
            $data = $form->getData();
            // Una reducción no va a ninguna iniciativa: si se eligió una antes de
            // cambiar a «de menos», el campo ya no se veía y se descarta.
            $less = $data['direction'] === FeeAdjustmentType::LESS;
            $em->persist(new FeeAdjustment($partner, ($less ? '-' : '') . $data['amount'], $data['reason'], $data['fromMonth'], $data['untilMonth'], $less ? null : $data['initiative'], $this->actor()));
            $em->flush();
            $this->addFlash('success', 'Ajuste de la cuota guardado.');

            return $this->redirectToPartner($partner);
        }

        return $this->render('fee_adjustment/new.html.twig', [
            'form' => $form,
            'partner' => $partner,
        ]);
    }

    /**
     * Ponerle fin: el último mes en que vale. Por defecto este mismo mes, que es lo
     * que se quiere al decir «ya no».
     */
    #[Route('/fee-adjustments/{id}/end', name: 'fee_adjustment_end', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function end(Request $request, FeeAdjustment $adjustment, EntityManagerInterface $em): Response
    {
        $partner = $adjustment->getPartner();
        if (!$this->isCsrfTokenValid('end-fee-adjustment-' . $adjustment->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToPartner($partner);
        }
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->request->get('month'));
        if ($month === false) {
            $this->addFlash('warning', 'Elige el último mes en que vale.');

            return $this->redirectToPartner($partner);
        }

        try {
            $adjustment->endIn($month);
            $em->flush();
            $this->addFlash('success', sprintf('El ajuste vale hasta %s.', $month->format('m/Y')));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage() . ' Si no tenía que existir, quítalo.');
        }

        return $this->redirectToPartner($partner);
    }

    /** Quitar un ajuste puesto por error. Para uno que ya no vale se le pone fin. */
    #[Route('/fee-adjustments/{id}/delete', name: 'fee_adjustment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, FeeAdjustment $adjustment, EntityManagerInterface $em): Response
    {
        $partner = $adjustment->getPartner();
        if ($this->isCsrfTokenValid('delete-fee-adjustment-' . $adjustment->getId(), (string) $request->request->get('_token'))) {
            $em->remove($adjustment);
            $em->flush();
            $this->addFlash('success', 'Ajuste quitado.');
        } else {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');
        }

        return $this->redirectToPartner($partner);
    }

    /** A la ficha del socix, a la altura del bloque de la cuota. */
    private function redirectToPartner(Partner $partner): Response
    {
        return $this->redirect($this->generateUrl('partner_show', ['id' => $partner->getId()]) . '#cuota');
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
