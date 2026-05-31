<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calendario de recogida del socio (B'): proyecta, para un (socio, mes), las
 * entregas que recibe ese mes — sin materializar nada que no esté ya.
 *
 * Regla por semana del mes (cada Basket del rango):
 *  1. Si ya hay un WeeklyBasket materializado para (socio, basket) → ese gana.
 *     Es el override: lo que el socio (o admin) tocó, o lo que el listado ya
 *     generó. Se lee con sus líneas reales.
 *  2. Si no, y el socio sale como candidato del patrón en projectForBasket
 *     (modalidad + cohorte + orden + nodo) → entrega de cesta PROYECTADA (dry),
 *     con líneas calculadas por el mismo computeItems del camino de escritura.
 *  3. Si no es candidato → ese mes no recibe esa semana.
 *
 * Devuelve HECHOS (qué entrega, qué fecha, qué lleva), no política: la
 * editabilidad, los deadlines y la regla R1 (compartidos sin selector) las
 * aplica la pantalla, no este proyector.
 *
 * Limitaciones v1 (marcadas, no olvidadas): la proyección dry NO refleja los
 * huevos-extra de socios con huevo más frecuente que la cesta (SANTOS-like,
 * que el generador resuelve en materializeExtraEggDeliveries) ni los cambios
 * puntuales (PartnerDeliveryShift). Ambos SÍ aparecen una vez materializados
 * (regla 1); solo faltan en la vista dry de meses futuros aún sin tocar.
 */
final class DeliveryCalendarProjector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
    ) {
    }

    /**
     * @param Partner $partner Socio cuyo calendario se proyecta.
     * @param int     $year    Año (p. ej. 2026).
     * @param int     $month   Mes 1-12.
     * @return list<array{date: \DateTimeInterface, basket: Basket, source: 'materialized'|'projected', weeklyBasket: WeeklyBasket, items: array<int, array{component: \App\Entity\BasketComponent, amount: string}>}>
     */
    public function projectMonth(Partner $partner, int $year, int $month): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');

        /** @var Basket[] $baskets */
        $baskets = $this->em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date BETWEEN :start AND :end')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $slots = [];
        foreach ($baskets as $basket) {
            $materialized = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($materialized !== null) {
                $slots[] = [
                    'date' => $materialized->getDeliveryDate() ?? $basket->getDate(),
                    'basket' => $basket,
                    'source' => 'materialized',
                    'weeklyBasket' => $materialized,
                    'items' => $this->readItems($materialized),
                ];
                continue;
            }

            $share = $this->candidateShareFor($partner, $basket);
            if ($share === null) {
                continue;
            }

            $projection = $this->generator->projectShareDelivery($basket, $share);
            if ($projection === null) {
                continue;
            }

            $slots[] = [
                'date' => $projection['deliveryDate'],
                'basket' => $basket,
                'source' => 'projected',
                'weeklyBasket' => $projection['weeklyBasket'],
                'items' => $projection['items'],
            ];
        }

        return $slots;
    }

    /**
     * Devuelve el PartnerBasketShare del socio si su patrón lo hace candidato a
     * repartir cesta en este Basket, o null. Reusa projectForBasket (que aplica
     * cohorte/orden/nodo igual que el camino de escritura) en vez de replicar la
     * regla: una sola fuente de verdad para "¿toca esta semana?".
     */
    private function candidateShareFor(Partner $partner, Basket $basket): ?\App\Entity\PartnerBasketShare
    {
        foreach ($this->generator->projectForBasket($basket) as $candidate) {
            if ($candidate->getPartner()->getId() === $partner->getId()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Lee las líneas reales de una entrega materializada en el mismo formato que
     * computeItems, para que pantalla y proyección hablen el mismo lenguaje.
     *
     * @return array<int, array{component: \App\Entity\BasketComponent, amount: string}>
     */
    private function readItems(WeeklyBasket $weeklyBasket): array
    {
        $lines = [];
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $weeklyBasket]) as $item) {
            $lines[] = ['component' => $item->getBasketComponent(), 'amount' => $item->getAmount()];
        }

        return $lines;
    }
}
