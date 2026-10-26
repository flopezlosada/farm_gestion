<?php

namespace App\Controller;

use App\Entity\AccountEntry;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnOutcome;
use App\Entity\ReturnStatus;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\Remittance\BankReturns;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los recibos devueltos: de quién es cada devolución del banco y cómo se cierra.
 *
 * Una devolución es una deuda abierta hasta que se elige una salida: volver a
 * cobrarla en una remesa, enlazar el pago que hizo por otro lado, o darla por no
 * debida o por perdida. El libro no se toca en ningún caso: la devolución ya la trae
 * el banco, y el nuevo cobro entra con su remesa.
 *
 * Ver, el permiso de lectura de contabilidad; todo lo demás, el de edición.
 */
#[Route('/gestion/accounting/returns')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class RemittanceReturnController extends AbstractController
{
    /** Las que tienen algo por hacer. */
    #[Route('', name: 'accounting_returns', methods: ['GET'])]
    public function index(RemittanceReturnRepository $returns): Response
    {
        return $this->render('accounting/returns.html.twig', [
            'returns' => $returns->findOpen(),
            'outcomes' => ReturnOutcome::cases(),
        ]);
    }

    /**
     * Dar un recibo por devuelto, con el apunte de la devolución si lo hay. Sin
     * apunte es un recibo que el banco rechazó antes de cobrarlo.
     */
    #[Route('/new', name: 'accounting_return_new', methods: ['POST'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function new(Request $request, RemittanceReturnRepository $returns, EntityManagerInterface $em): Response
    {
        $line = $em->find(RemittanceLine::class, $request->request->getInt('line'));
        if ($line === null) {
            $this->addFlash('warning', 'Elige el recibo.');

            return $this->redirectToRoute('accounting_remittances');
        }
        $remittance = $line->getRemittance();
        $back = $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId(), '_fragment' => 'devoluciones']);
        if (!$this->isCsrfTokenValid('return-remittance-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if ($returns->findOneBy(['line' => $line]) !== null) {
            $this->addFlash('warning', sprintf('El recibo de %s ya está dado por devuelto.', $line->getDebtorName()));

            return $back;
        }

        $entryId = $request->request->getInt('entry');
        $entry = $entryId > 0 ? $em->find(AccountEntry::class, $entryId) : null;
        $error = match (true) {
            $entryId === 0 => null,
            $entry === null || BankReturns::reference($entry->getConcept(), BankReturns::RETURN_PREFIX) === null => 'Ese apunte no es una devolución de remesa del banco.',
            $returns->findOneBy(['entry' => $entry]) !== null => 'Esa devolución del banco ya está ligada a otro recibo.',
            default => null,
        };
        if ($error !== null) {
            $this->addFlash('warning', $error);

            return $back;
        }

        try {
            $em->persist(new RemittanceReturn($line, $entry, $this->actor()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $back;
        }
        $em->flush();
        $this->addFlash('success', sprintf('Recibo de %s dado por devuelto. Queda como deuda abierta hasta que elijas qué se hace.', $line->getDebtorName()));

        return $back;
    }

    /**
     * Elegir la salida (volver a cobrar, no se debía, perdido), deshacerla, o quitar la
     * devolución entera si se marcó el recibo equivocado.
     */
    #[Route('/{id}/resolve', name: 'accounting_return_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function resolve(Request $request, RemittanceReturn $return, EntityManagerInterface $em): Response
    {
        $back = $this->backTo($request, $return);
        if (!$this->isCsrfTokenValid('resolve-return-' . $return->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }

        $name = $return->getLine()->getDebtorName();
        try {
            $done = match ((string) $request->request->get('action')) {
                ReturnOutcome::Recharge->value => $this->apply(fn () => $return->recharge($this->actor()), sprintf('El recibo de %s se volverá a cobrar: añádelo desde el próximo borrador de remesa.', $name)),
                ReturnOutcome::NotOwed->value => $this->apply(fn () => $return->notOwed($this->actor()), sprintf('Devolución de %s cerrada: no se debía.', $name)),
                ReturnOutcome::WrittenOff->value => $this->apply(fn () => $return->writeOff($this->actor()), sprintf('Devolución de %s cerrada: se da por perdida.', $name)),
                'reopen' => $this->apply($return->reopen(...), sprintf('La devolución de %s vuelve a estar pendiente.', $name)),
                'undo' => $this->undo($return, $em),
                default => null,
            };
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $back;
        }
        if ($done === null) {
            $this->addFlash('warning', 'Elige qué se hace con la devolución.');

            return $back;
        }

        $em->flush();
        $this->addFlash('success', $done);

        return $back;
    }

    /**
     * «Pagado aparte»: elegir el ingreso con que lo pagó, entre los de su importe desde
     * la remesa. Si no sale, es que falta anotarlo en el libro.
     */
    #[Route('/{id}/paid', name: 'accounting_return_paid', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function paid(Request $request, RemittanceReturn $return, AccountEntryRepository $entries, EntityManagerInterface $em): Response
    {
        $line = $return->getLine();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('resolve-return-' . $return->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

                return $this->redirectToRoute('accounting_return_paid', ['id' => $return->getId()]);
            }
            $entry = $em->find(AccountEntry::class, $request->request->getInt('entry'));
            // Los mismos que ofrece la pantalla: un ingreso que no es un traspaso y no
            // paga ya otra devolución, aunque el POST traiga otro.
            $offered = array_map(static fn (AccountEntry $e): int => (int) $e->getId(), $entries->findIncomeOf($line->getAmount(), $line->getRemittance()->getPeriod()));
            try {
                $entry !== null && \in_array($entry->getId(), $offered, true) ? $return->paidWith($entry, $this->actor()) : throw new \InvalidArgumentException('Elige uno de los pagos de la lista.');
            } catch (\InvalidArgumentException|\LogicException $e) {
                $this->addFlash('warning', $e->getMessage());

                return $this->redirectToRoute('accounting_return_paid', ['id' => $return->getId()]);
            }
            $em->flush();
            $this->addFlash('success', sprintf('Devolución de %s cerrada: lo pagó aparte el %s.', $line->getDebtorName(), $entry->getDate()?->format('d/m/Y')));

            return $this->redirectToRoute('accounting_returns');
        }

        return $this->render('accounting/return_paid.html.twig', [
            'return' => $return,
            'candidates' => $entries->findIncomeOf($line->getAmount(), $line->getRemittance()->getPeriod()),
        ]);
    }

    /**
     * Meter en un borrador de remesa todas las devoluciones que se van a volver a
     * cobrar: un recibo nuevo por cada una.
     */
    #[Route('/recharge-into/{id}', name: 'accounting_return_recharge_into', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_CONTABILIDAD_EDIT')]
    public function rechargeInto(Request $request, Remittance $remittance, RemittanceReturnRepository $returns, EntityManagerInterface $em): Response
    {
        $back = $this->redirectToRoute('accounting_remittance_show', ['id' => $remittance->getId()]);
        if (!$this->isCsrfTokenValid('recharge-into-' . $remittance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $back;
        }
        if (!$remittance->isDraft()) {
            $this->addFlash('warning', 'Una remesa enviada no se cambia.');

            return $back;
        }

        $pending = $returns->findToRecharge();
        if ($pending === []) {
            $this->addFlash('warning', 'No hay ningún recibo devuelto por volver a cobrar.');

            return $back;
        }
        foreach ($pending as $return) {
            $line = RemittanceLine::retryOf($return->getLine());
            $remittance->addLine($line);
            $return->rechargeIn($line);
        }
        $em->flush();
        $this->addFlash('success', \count($pending) === 1
            ? 'Añadido 1 recibo devuelto a la remesa. Revisa la cuenta antes de enviarla.'
            : sprintf('Añadidos %d recibos devueltos a la remesa. Revisa las cuentas antes de enviarla.', \count($pending)));

        return $back;
    }

    /**
     * Quitar una devolución marcada por error. Sólo si está pendiente: una cerrada se
     * reabre antes, para que quitarla no borre de paso lo que se decidió.
     */
    private function undo(RemittanceReturn $return, EntityManagerInterface $em): string
    {
        if ($return->getStatus() !== ReturnStatus::Pending) {
            throw new \LogicException('Sólo se quita una devolución pendiente: deshaz antes lo que se eligió.');
        }
        $em->remove($return);

        return sprintf('El recibo de %s ya no está dado por devuelto.', $return->getLine()->getDebtorName());
    }

    /** Aplica un cambio y devuelve el mensaje de lo hecho. */
    private function apply(callable $change, string $done): string
    {
        $change();

        return $done;
    }

    /** Se vuelve a donde se pidió: la lista de devoluciones o la ficha de la remesa. */
    private function backTo(Request $request, RemittanceReturn $return): Response
    {
        return $request->request->get('from') === 'remittance'
            ? $this->redirectToRoute('accounting_remittance_show', ['id' => $return->getLine()->getRemittance()->getId(), '_fragment' => 'devoluciones'])
            : $this->redirectToRoute('accounting_returns');
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
