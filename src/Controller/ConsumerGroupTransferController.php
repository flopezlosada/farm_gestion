<?php

namespace App\Controller;

use App\Entity\BankStatementLine;
use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupTransfer;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupTransferRepository;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use App\Service\ConsumerGroup\ConsumerGroupTransfers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las transferencias del banco con que se pagan los pedidos del grupo de consumo:
 * las que el cruce no supo asignar solo, para elegir a mano qué pedido pagan, y las
 * ya asignadas, para deshacer un error.
 *
 * Es de contabilidad (son movimientos del banco) y sólo existe con el grupo de
 * consumo en marcha. Ver y elegir: lo que pide cada ruta.
 */
#[Route('/gestion/accounting/consumer-group-transfers')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class ConsumerGroupTransferController extends AbstractController
{
    /** Cuántas asignadas y descartadas se enseñan: las recientes, que son las que se revisan. */
    private const RECENT = 30;

    /** Lo que falta por elegir, con sus pedidos probables, y lo último decidido. */
    #[Route('', name: 'accounting_cg_transfers', methods: ['GET'])]
    public function index(ConsumerGroupTransfers $transfers, ConsumerGroupTransferRepository $repository): Response
    {
        return $this->render('accounting/consumer_group_transfers.html.twig', [
            'pending' => $transfers->pending(),
            'assigned' => $repository->findAssigned(self::RECENT),
            'dismissed' => $repository->findDismissed(self::RECENT),
        ]);
    }

    /**
     * Este movimiento paga estos pedidos: los marcados de entre los propuestos y el
     * elegido en «otro pedido». Sólo pedidos que siguen por pagar.
     */
    #[Route('/lines/{id}/assign', name: 'accounting_cg_transfer_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function assign(
        Request $request,
        BankStatementLine $line,
        ConsumerGroupTransfers $transfers,
        ConsumerGroupOrderRepository $orders,
        ConsumerGroupEventRecorder $recorder,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('cg_transfer_assign_'.$line->getId(), (string) $request->request->get('_token'))) {
            return $this->back('Token de seguridad inválido.');
        }

        $ids = array_values(array_unique(array_map('intval', array_filter([
            ...$request->request->all('orders'),
            $request->request->get('other'),
        ]))));
        if ($ids === []) {
            return $this->back('Marca un pedido, o elige uno en «otro pedido».');
        }
        $chosen = array_values(array_filter(
            $orders->findPayableByTransfer(),
            static fn (ConsumerGroupOrder $o): bool => \in_array($o->getId(), $ids, true),
        ));
        if ($chosen === [] || \count($chosen) !== \count($ids)) {
            return $this->back('Alguno de los pedidos elegidos ya está pagado o ya no se puede pagar. Revisa la lista.');
        }

        try {
            $transfers->assign($line, $chosen, $this->getUser());
        } catch (\InvalidArgumentException $e) {
            return $this->back($e->getMessage());
        }
        foreach ($chosen as $order) {
            $recorder->record(
                ConsumerGroupEventLog::KIND_ORDER_PAYMENT_TOGGLED,
                $order->getRound(),
                $this->getUser(),
                sprintf('Pedido de %s pagado con la transferencia del %s (%s €).', $order->getPartner(), $line->getOperationDate()->format('d/m/Y'), self::euros($line->getAmountCents())),
            );
        }
        $em->flush();

        $due = array_sum(array_map(static fn (ConsumerGroupOrder $o): int => (int) round($o->getTotal() * 100), $chosen));
        $due === $line->getAmountCents()
            ? $this->addFlash('success', 'Asignada. El pedido consta pagado.')
            : $this->addFlash('warning', sprintf(
                'Asignada, pero no cuadra: la transferencia trae %s € y los pedidos suman %s €. Si no es lo que querías, deshazla abajo.',
                self::euros($line->getAmountCents()),
                self::euros($due),
            ));

        return $this->redirectToRoute('accounting_cg_transfers');
    }

    /** Este movimiento no paga ningún pedido: deja de pedir que se elija. */
    #[Route('/lines/{id}/dismiss', name: 'accounting_cg_transfer_dismiss', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function dismiss(Request $request, BankStatementLine $line, ConsumerGroupTransfers $transfers, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cg_transfer_dismiss_'.$line->getId(), (string) $request->request->get('_token'))) {
            return $this->back('Token de seguridad inválido.');
        }
        $transfers->dismiss($line);
        $em->flush();
        $this->addFlash('success', 'Apartada: no es el pago de un pedido.');
        if ($transfers->staysBookedAsConsumerGroup($line)) {
            $this->addFlash('warning', 'Su apunte sigue en la partida «Grupo de consumo», anotado solo. Corrígelo desde el extracto para que los informes no lo cuenten como ingreso del grupo.');
        }

        return $this->redirectToRoute('accounting_cg_transfers');
    }

    /**
     * Deshace una asignación: los pedidos vuelven a pendientes de pago y el movimiento
     * a la lista para elegir.
     */
    #[Route('/{id}/release', name: 'accounting_cg_transfer_release', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function release(
        Request $request,
        ConsumerGroupTransfer $transfer,
        ConsumerGroupTransfers $transfers,
        ConsumerGroupEventRecorder $recorder,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('cg_transfer_release_'.$transfer->getId(), (string) $request->request->get('_token'))) {
            return $this->back('Token de seguridad inválido.');
        }
        $orders = $transfer->getOrders();
        $transfers->release($transfer);
        foreach ($orders as $order) {
            $recorder->record(
                ConsumerGroupEventLog::KIND_ORDER_PAYMENT_TOGGLED,
                $order->getRound(),
                $this->getUser(),
                sprintf('Pedido de %s vuelve a pendiente de pago: la transferencia del %s no era suya.', $order->getPartner(), $transfer->getLine()->getOperationDate()->format('d/m/Y')),
            );
        }
        $em->flush();
        $this->addFlash('success', 'Deshecha. El movimiento vuelve a la lista para elegir su pedido.');

        return $this->redirectToRoute('accounting_cg_transfers');
    }

    /** Un movimiento apartado por error vuelve a la lista para elegir su pedido. */
    #[Route('/{id}/reopen', name: 'accounting_cg_transfer_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function reopen(Request $request, ConsumerGroupTransfer $transfer, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cg_transfer_reopen_'.$transfer->getId(), (string) $request->request->get('_token'))) {
            return $this->back('Token de seguridad inválido.');
        }
        $transfer->reopen();
        $em->flush();

        return $this->redirectToRoute('accounting_cg_transfers');
    }

    /** Vuelve a la pantalla con un aviso. */
    private function back(string $warning): Response
    {
        $this->addFlash('warning', $warning);

        return $this->redirectToRoute('accounting_cg_transfers');
    }

    /** Céntimos en euros, a la española. */
    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
