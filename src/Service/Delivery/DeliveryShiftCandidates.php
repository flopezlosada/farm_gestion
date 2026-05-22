<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;

/**
 * Calcula los Basket destino válidos para un cambio puntual de viernes
 * según la modalidad del socix.
 *
 *  - Quincenal (id=2): solo el Basket inmediatamente posterior al `from`.
 *  - Mensual   (id=3): cualquier otro viernes del mismo mes que `from`.
 *  - Resto (semanal id=1, compartida id=4, sólo huevos id=5): lista vacía
 *    — no aplica el shift.
 *
 * Esta lógica vivía inline en `PanelController::shiftDestinationCandidates`;
 * extraída para reutilizarla desde la pantalla admin de cambios de cesta.
 */
final class DeliveryShiftCandidates
{
    private const SHARE_BIWEEKLY = 2;
    private const SHARE_MONTHLY = 3;

    public function __construct(
        private readonly BasketRepository $basketRepository,
    ) {
    }

    /**
     * @return Basket[]
     */
    public function findFor(PartnerBasketShare $activeShare, Basket $from): array
    {
        $shareTypeId = $activeShare->getBasketShare()?->getId();

        if ($shareTypeId === self::SHARE_BIWEEKLY) {
            $next = $this->basketRepository->findNextAfter($from);
            return $next !== null ? [$next] : [];
        }

        if ($shareTypeId === self::SHARE_MONTHLY) {
            return $this->basketRepository->findOtherBasketsInSameMonth($from);
        }

        return [];
    }
}
