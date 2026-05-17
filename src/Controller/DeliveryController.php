<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Repository\BasketRepository;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerRepository;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyDeliveryReport;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vista detallada del reparto de un viernes concreto. Se accede desde el
 * calendario interno (pinchando en un evento "Reparto · cesta #N") y deja
 * ver el listado completo con búsqueda, orden por nombre/nodo/grupo y la
 * marca de excepción de calendario si la hay.
 *
 * Bajo el mismo prefijo se cuelga la gestión de cambios puntuales de
 * viernes (DeliveryShift): un admin puede registrar el cambio que pide
 * un socio por canal externo y/o cancelar uno en curso, con opción de
 * forzar reglas bypassables del validator.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryController extends AbstractAppController
{
    #[Route('/cambios-viernes', name: 'delivery_shifts_index', methods: ['GET'])]
    public function shiftsIndex(
        PartnerDeliveryShiftRepository $shiftRepo,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
    ): Response {
        $shifts = $shiftRepo->createQueryBuilder('s')
            ->innerJoin('s.fromBasket', 'fb')
            ->orderBy('fb.date', 'ASC')
            ->getQuery()
            ->getResult();

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $upcomingBaskets = $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(16)
            ->getQuery()
            ->getResult();

        return $this->render('delivery/shifts_index.html.twig', [
            'shifts' => $shifts,
            'partners' => $partnerRepo->findBy([], ['surname' => 'ASC', 'name' => 'ASC']),
            'upcoming_baskets' => $upcomingBaskets,
        ]);
    }

    #[Route('/cambios-viernes/aplicar', name: 'delivery_shifts_apply', methods: ['POST'])]
    public function shiftsApply(
        Request $request,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        if (!$this->isCsrfTokenValid('delivery_shift_apply', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index');
        }

        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));
        $from = $basketRepo->find((int) $request->request->get('from_basket_id'));
        $to = $basketRepo->find((int) $request->request->get('to_basket_id'));
        $force = $request->request->getBoolean('force_bypassable');

        if (!$partner instanceof Partner || !$from instanceof Basket || !$to instanceof Basket) {
            $this->addFlash('error', 'Faltan datos del cambio: socio, viernes origen o viernes destino.');
            return $this->redirectToRoute('delivery_shifts_index');
        }

        $violations = $validator->validate($partner, $from, $to);
        $blocking = $validator->blocking($violations);
        if (!empty($blocking)) {
            foreach ($blocking as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('delivery_shifts_index');
        }

        $bypassable = $validator->bypassable($violations);
        if (!empty($bypassable) && !$force) {
            foreach ($bypassable as $v) {
                $this->addFlash('warning', $v->message . ' Marca "forzar" si quieres aplicarlo igualmente.');
            }
            return $this->redirectToRoute('delivery_shifts_index');
        }

        try {
            $applier->apply($partner, $from, $to, actor: 'gestor:' . $this->getUser()->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('delivery_shifts_index');
        }

        $this->addFlash('notice', sprintf(
            'Cambio aplicado: %s recoge el %s en lugar del %s.',
            trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? '')),
            $to->getDate()->format('d/m/Y'),
            $from->getDate()->format('d/m/Y'),
        ));
        return $this->redirectToRoute('delivery_shifts_index');
    }

    #[Route('/cambios-viernes/{id}/cancelar', name: 'delivery_shifts_cancel', methods: ['POST'])]
    public function shiftsCancel(
        Request $request,
        PartnerDeliveryShift $shift,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        if (!$this->isCsrfTokenValid('delivery_shift_cancel_' . $shift->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index');
        }

        $force = $request->request->getBoolean('force_bypassable');
        $violations = $validator->validate($shift->getPartner(), $shift->getFromBasket(), $shift->getToBasket());

        if (!$force) {
            $blocking = $validator->blocking($violations);
            if (!empty($blocking)) {
                foreach ($blocking as $v) {
                    $this->addFlash('error', $v->message);
                }
                return $this->redirectToRoute('delivery_shifts_index');
            }
        }

        $applier->cancel($shift, actor: 'gestor:' . $this->getUser()->getId());

        $this->addFlash('notice', 'Cambio cancelado.');
        return $this->redirectToRoute('delivery_shifts_index');
    }

    #[Route('/{basketId}', name: 'delivery_show', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function show(
        #[MapEntity(id: 'basketId')] Basket $basket,
        WeeklyBasketGenerator $generator,
        DeliveryExceptionRepository $exceptions,
    ): Response {
        $report = $generator->generateForBasket($basket);
        $exception = $exceptions->findByFriday($basket->getDate());

        return $this->render('delivery/show.html.twig', [
            'basket' => $basket,
            'report' => $report,
            'exception' => $exception,
            'rows' => $this->flattenForTable($report),
        ]);
    }

    /**
     * Aplana las cinco colecciones del report en una sola lista plana para
     * la tabla con DataTables. Cada fila trae todo lo necesario para que
     * la plantilla no haga lógica.
     *
     * @return array<int, array{name:string, surname:string, modality:string, node:?string, group:?string, amount:int}>
     */
    private function flattenForTable(WeeklyDeliveryReport $report): array
    {
        $rows = [];
        $modalities = [
            ['weekly_partners', 'Semanal'],
            ['biweekly_partners', 'Quincenal'],
            ['monthly_partners', 'Mensual'],
            ['old_half_basket_partners', 'Media cesta'],
            ['only_egg_partners', 'Solo huevos'],
        ];

        foreach ($modalities as [$prop, $label]) {
            foreach ($report->$prop as $item) {
                $partner = $item->getPartner();
                $node = $partner->getWeeklyBasketGroup();
                $share = method_exists($item, 'getPartnerBasketShare') ? $item->getPartnerBasketShare() : null;
                $group = $share?->getDeliveryGroup();

                $rows[] = [
                    'name' => $partner->getName() ?? '',
                    'surname' => $partner->getSurname() ?? '',
                    'modality' => $label,
                    'node' => $node?->getName(),
                    'group' => $group,
                    'amount' => (int) $item->getAmount(),
                ];
            }
        }

        return $rows;
    }
}
