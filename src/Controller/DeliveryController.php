<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyDeliveryReport;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vista detallada del reparto de un viernes concreto. Se accede desde el
 * calendario interno (pinchando en un evento "Reparto · cesta #N") y deja
 * ver el listado completo con búsqueda, orden por nombre/nodo/grupo y la
 * marca de excepción de calendario si la hay.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryController extends AbstractAppController
{
    #[Route('/{basketId}', name: 'delivery_show', methods: ['GET'])]
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
