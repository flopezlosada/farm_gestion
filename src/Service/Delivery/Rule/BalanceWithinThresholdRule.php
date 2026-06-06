<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Repository\BasketRepository;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Balance: prohíbe shifts que dejen una diferencia de más de N cestas
 * entre dos viernes consecutivos. Por defecto N=3 (la cifra que la
 * administración usa como umbral operativo en Excel hoy).
 *
 * El conteo se calcula sobre WeeklyBasket con status=1 (se recogen):
 * las marcadas como "no recoge" no se incluyen porque no añaden carga
 * de cosecha.
 *
 * Esta regla **es bypassable**: admin puede forzar el cambio aunque
 * la rompa (por ejemplo, en semanas especiales). El socix nunca puede
 * saltársela desde el panel.
 *
 * Para evaluar la regla se calcula el conteo de cada Basket entre
 * `prev(from)` y `next(to)` aplicando el shift hipotéticamente
 * (`from` pierde una cesta, `to` gana una). Si algún par consecutivo
 * supera el umbral, devuelve violación.
 */
final class BalanceWithinThresholdRule implements DeliveryShiftRule
{
    public const ID = 'balance_within_threshold';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $threshold = 3,
    ) {
    }

    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
    {
        /** @var BasketRepository $basketRepo */
        $basketRepo = $this->em->getRepository(Basket::class);
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $earliest = $basketRepo->findPreviousBefore($from) ?? $from;
        $latest = $basketRepo->findNextAfter($to) ?? $to;

        $range = $basketRepo->findBetweenDates($earliest->getDate(), $latest->getDate());
        if (count($range) < 2) {
            return null;
        }

        $counts = [];
        foreach ($range as $b) {
            $count = $wbRepo->countPickedInBasket($b);
            if ($b->getId() === $from->getId()) {
                $count = max(0, $count - 1);
            }
            if ($b->getId() === $to->getId()) {
                $count++;
            }
            $counts[] = $count;
        }

        for ($i = 1, $n = count($counts); $i < $n; $i++) {
            $diff = abs($counts[$i] - $counts[$i - 1]);
            if ($diff > $this->threshold) {
                return new DeliveryShiftViolation(
                    self::ID,
                    sprintf(
                        'Con este cambio, la diferencia de cestas entre dos viernes consecutivos pasaría a %d, por encima del límite de %d. La administración puede forzarlo si lo considera oportuno.',
                        $diff,
                        $this->threshold,
                    ),
                    bypassable: true,
                );
            }
        }

        return null;
    }
}
