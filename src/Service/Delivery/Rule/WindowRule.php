<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;
use App\Repository\PartnerBasketShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Window: limita los destinos posibles del cambio puntual de viernes
 * según la modalidad del socio.
 *
 *  - Quincenal: el destino debe ser el Basket inmediatamente posterior
 *    al `from`. Equivale a "saltar a la semana de mi grupo opuesto".
 *  - Mensual: el destino debe ser cualquier viernes del mismo mes que
 *    el `from`. Mantiene la regla de "1 cesta por mes" del socio.
 *  - Cualquier otra modalidad (semanal, half, sólo huevos): el shift
 *    no aplica. Los semanales tienen acción "no recojo esta semana"
 *    por separado; el resto se delega a admin si surge necesidad.
 *
 * Esta regla NO es bypassable: violarla romp{e}ría invariantes del
 * modelo (un mensual recogería dos veces el mismo mes, p.ej).
 */
final class WindowRule implements DeliveryShiftRule
{
    public const ID = 'window';

    /** ID de BasketShare quincenal. */
    private const SHARE_BIWEEKLY = 2;
    /** ID de BasketShare mensual. */
    private const SHARE_MONTHLY = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
    {
        /** @var PartnerBasketShareRepository $shareRepo */
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);
        $share = $shareRepo->findActiveForPartner($partner);

        if ($share === null) {
            return new DeliveryShiftViolation(
                self::ID,
                'No se puede pedir cambio puntual de viernes: este socio no tiene una suscripción activa.',
                bypassable: false,
            );
        }

        $shareTypeId = $share->getBasketShare()?->getId();

        if ($shareTypeId === self::SHARE_BIWEEKLY) {
            /** @var BasketRepository $basketRepo */
            $basketRepo = $this->em->getRepository(Basket::class);
            $expected = $basketRepo->findNextAfter($from);

            if ($expected === null || $expected->getId() !== $to->getId()) {
                return new DeliveryShiftViolation(
                    self::ID,
                    'Como suscripción quincenal, solo puedes cambiar al viernes inmediatamente siguiente al que te tocaba.',
                    bypassable: false,
                );
            }
            return null;
        }

        if ($shareTypeId === self::SHARE_MONTHLY) {
            if ($from->getDate()->format('Y-m') !== $to->getDate()->format('Y-m')) {
                return new DeliveryShiftViolation(
                    self::ID,
                    'Como suscripción mensual, el viernes de cambio debe ser otro viernes del mismo mes que el original.',
                    bypassable: false,
                );
            }
            return null;
        }

        return new DeliveryShiftViolation(
            self::ID,
            'El cambio puntual de viernes solo se aplica a suscripciones quincenales o mensuales.',
            bypassable: false,
        );
    }
}
