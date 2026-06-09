<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Repository\BasketRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resetea el calendario de recogida de un socio en un mes A SU PATRÓN: deshace los cambios
 * puntuales (mover/no-recoge/recuperar, cestas extra y traslados de nodo) y deja las entregas
 * como las generaría el listado.
 *
 * Red de seguridad para cuando un estado queda enredado (ciclos de shifts, cestas perdidas
 * de vista, etc.). Pensado para el botón de admin del calendario.
 *
 * Reglas:
 *  - R1: las cestas compartidas no se resetean aquí (su día lo marca la alternancia con el
 *    otro hogar) → se lanza excepción y el caller avisa.
 *  - Mes GENERADO (hay WeeklyBasket de algún socio esa semana): se borran los WB del socio
 *    en el mes y se RE-MATERIALIZA su patrón con {@see WeeklyBasketGenerator::materializeForPartner}
 *    (cubre cesta Y solo-huevo de SANTOS-like).
 *  - Mes SIN GENERAR: NO se materializa nada (un WB suelto dispararía la rama
 *    reuseExistingWeeklyBaskets del generador y dejaría fuera al resto) — basta con borrar
 *    los intents del socio; el patrón se proyecta limpio.
 */
final class PartnerMonthResetter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
        private readonly BasketRepository $basketRepository,
    ) {
    }

    /**
     * @param Partner $partner
     * @param int     $year
     * @param int     $month 1-12
     * @throws \LogicException Si el socio es de cesta compartida (R1).
     */
    public function reset(Partner $partner, int $year, int $month): void
    {
        if ($partner->getSharePartner() !== null) {
            throw new \LogicException('Esta cesta es compartida: su día lo marca la alternancia con el otro hogar, no se resetea desde aquí.');
        }

        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');

        /** @var Basket[] $baskets */
        $baskets = $this->basketRepository->createQueryBuilder('b')
            ->where('b.date BETWEEN :s AND :e')
            ->setParameter('s', $start->format('Y-m-d'))
            ->setParameter('e', $end->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();
        if ($baskets === []) {
            return;
        }
        $monthBasketIds = array_map(static fn (Basket $b): int => $b->getId(), $baskets);

        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);
        /** @var WeeklyBasketRepository $wbRepo */
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        // 1) Borrar TODOS los overrides puntuales del socio que TOQUEN el mes: resetear a
        //    patrón es justamente quitar las desviaciones de una semana.
        //    a) Cambios de día / no-recoge / recuperar (PartnerDeliveryShift), por origen o destino.
        foreach ($shiftRepo->findBy(['partner' => $partner]) as $shift) {
            if (in_array($shift->getFromBasket()?->getId(), $monthBasketIds, true)
                || in_array($shift->getToBasket()?->getId(), $monthBasketIds, true)) {
                $this->em->remove($shift);
            }
        }
        //    b) Cestas extra (PartnerBasketExtra) y c) traslados de nodo (PartnerNodeOverride),
        //       ambos de una sola semana → basta comprobar su basket.
        foreach ($this->em->getRepository(PartnerBasketExtra::class)->findBy(['partner' => $partner]) as $extra) {
            if (in_array($extra->getBasket()?->getId(), $monthBasketIds, true)) {
                $this->em->remove($extra);
            }
        }
        foreach ($this->em->getRepository(PartnerNodeOverride::class)->findBy(['partner' => $partner]) as $override) {
            if (in_array($override->getBasket()?->getId(), $monthBasketIds, true)) {
                $this->em->remove($override);
            }
        }
        $this->em->flush();

        // 2) Por cada semana del mes: si está GENERADA, borrar la entrega del socio y
        //    re-materializar su patrón. Si NO está generada, no se materializa (basta el
        //    borrado de intents del paso 1).
        foreach ($baskets as $basket) {
            $generated = $wbRepo->findOneBy(['basket' => $basket->getId()]) !== null;

            $wb = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($wb !== null) {
                foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
                    $this->em->remove($item);
                }
                $this->em->remove($wb);
                $this->em->flush();
            }

            if ($generated) {
                $this->generator->materializeForPartner($partner, $basket);
            }
        }
    }
}
