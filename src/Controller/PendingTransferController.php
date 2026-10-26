<?php

namespace App\Controller;

use App\Entity\PendingTransfer;
use App\Entity\User;
use App\Form\AccountTransferType;
use App\Repository\PendingTransferRepository;
use App\Service\Accounting\TransferRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los traspasos que alguien ha contado por el bot y esperan a entrar en el libro.
 *
 * Confirmar es el formulario de traspaso de siempre, ya relleno con lo que se entendió:
 * quien confirma corrige lo que haga falta y sólo entonces se anotan los dos apuntes.
 */
#[Route('/gestion/accounting/transfer/pending')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class PendingTransferController extends AbstractController
{
    /** Los que faltan por confirmar. */
    #[Route('/', name: 'accounting_transfer_pending', methods: ['GET'])]
    public function index(PendingTransferRepository $pending): Response
    {
        return $this->render('accounting/transfer_pending.html.twig', [
            'transfers' => $pending->findUnconfirmed(),
        ]);
    }

    /**
     * Revisarlo y anotarlo en el libro. Uno ya confirmado no se vuelve a anotar: se
     * manda a la lista con un aviso.
     */
    #[Route('/{id}', name: 'accounting_transfer_pending_confirm', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function confirm(PendingTransfer $transfer, Request $request, EntityManagerInterface $em, TransferRecorder $transfers, PendingTransferRepository $pending): Response
    {
        if ($transfer->isConfirmed()) {
            $this->addFlash('info', 'Ese traspaso ya está anotado en el libro.');

            return $this->redirectToRoute('accounting_transfer_pending');
        }

        $form = $this->createForm(AccountTransferType::class, [
            'date' => $transfer->getDate(),
            'from' => $transfer->getFrom(),
            'to' => $transfer->getTo(),
            'amount' => $transfer->getAmount() !== null ? (float) $transfer->getAmount() : null,
            'concept' => $transfer->getConcept(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = $this->getUser();
            $done = $em->wrapInTransaction(function () use ($pending, $transfer, $transfers, $data, $user): bool {
                $locked = $pending->findLocked((int) $transfer->getId());
                if ($locked === null || $locked->isConfirmed()) {
                    return false;
                }
                $locked->confirm($transfers->record(
                    $data['from'],
                    $data['to'],
                    (string) $data['amount'],
                    $data['date'],
                    trim((string) $data['concept']),
                    $user instanceof User ? $user : null,
                ));

                return true;
            });
            if (!$done) {
                $this->addFlash('warning', 'Mientras lo revisabas, alguien lo ha anotado o lo ha tirado desde el bot. No he anotado nada.');

                return $this->redirectToRoute('accounting_transfer_pending');
            }
            $this->addFlash('success', sprintf(
                'Traspaso anotado: %s € de %s a %s.',
                number_format((float) $data['amount'], 2, ',', '.'),
                $data['from']->getName(),
                $data['to']->getName(),
            ));

            return $this->redirectToRoute('accounting_transfer_pending');
        }

        return $this->render('accounting/transfer_form.html.twig', [
            'form' => $form->createView(),
            'pending' => $transfer,
        ]);
    }

    /** No era un traspaso, o ya estaba anotado: se tira sin tocar el libro. */
    #[Route('/{id}/discard', name: 'accounting_transfer_pending_discard', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function discard(PendingTransfer $transfer, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('accounting_transfer_pending_discard_'.$transfer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('accounting_transfer_pending');
        }
        if ($transfer->isConfirmed()) {
            $this->addFlash('warning', 'Ese traspaso ya está en el libro: si no era, bórralo desde Movimientos.');

            return $this->redirectToRoute('accounting_transfer_pending');
        }
        $em->remove($transfer);
        $em->flush();
        $this->addFlash('success', 'Descartado. El libro no se ha tocado.');

        return $this->redirectToRoute('accounting_transfer_pending');
    }
}
