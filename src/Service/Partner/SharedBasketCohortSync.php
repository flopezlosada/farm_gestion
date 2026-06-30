<?php

namespace App\Service\Partner;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantiene en sincronía el "cuándo recoge" de dos socios que COMPARTEN una
 * cesta (Partner.sharePartner mutuo, modalidades 4/6/7).
 *
 * Una cesta compartida es UNA cesta física que dos hogares parten: ambos
 * recogen el MISMO día. Por tanto su turno de viernes (delivery_group, en la
 * quincenal compartida) y su orden mensual (day_month_order, en la mensual
 * compartida) tienen que coincidir. El generador selecciona a cada socio por
 * SU propio delivery_group/day_month_order, así que si uno cambia de quincena
 * y el otro no, acaban en viernes distintos y la cesta compartida se parte en
 * dos (bug 2026-06-30: Ana Villa / Jose Carrascosa).
 *
 * Cuando se edita el turno/orden de un socio con cesta compartida, hay que
 * copiarlo al par y reconciliar también SUS semanas ya generadas. La modalidad
 * y los huevos NO se tocan: cada hogar paga sus huevos y el emparejamiento ya
 * garantiza misma modalidad (ver PartnerController::addSharePartner).
 */
class SharedBasketCohortSync
{
    public function __construct(
        private readonly PartnerBasketShareRepository $shareRepository,
        private readonly WeeklyBasketGenerator $generator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Propaga el turno (delivery_group) y el orden mensual (day_month_order) de
     * la cesta $source a la del socio par, si comparten cesta y difieren.
     * Reconcilia las semanas ya generadas del par desde $from.
     *
     * $source se pasa explícito (la PBS recién editada/creada), NO se re-busca
     * por "activa hoy": en un cambio con fecha efectiva futura la PBS nueva aún
     * no está vigente hoy y se perdería.
     *
     * @param PartnerBasketShare $source Cesta cuyo turno/orden acaba de cambiar.
     * @param \DateTimeInterface $from   Fecha desde la que reconciliar al par.
     * @return Partner|null El socio par actualizado, o null si no había nada que
     *                      sincronizar (sin pareja, cesta no compartida, par sin
     *                      cesta activa, o ya estaban alineados).
     */
    public function syncFrom(PartnerBasketShare $source, \DateTimeInterface $from): ?Partner
    {
        if (!in_array($source->getBasketShare()?->getId(), BasketShare::IDS_SHARED, true)) {
            return null;
        }

        $mate = $source->getPartner()?->getSharePartner();
        if ($mate === null) {
            return null;
        }

        // La cesta del par a alinear es su suscripción ACTIVA en curso, no "la
        // vigente en $from": si la del par también empieza en el futuro (cambio
        // programado), findActiveForPartner($from) no la vería — el mismo punto
        // ciego del bug original. findLatestActiveForPartner la coge igual.
        $mateShare = $this->shareRepository->findLatestActiveForPartner($mate);
        if ($mateShare === null) {
            return null;
        }

        $sameGroup = $mateShare->getDeliveryGroup() === $source->getDeliveryGroup();
        $sameOrder = $mateShare->getDayMonthOrder() === $source->getDayMonthOrder();
        if ($sameGroup && $sameOrder) {
            return null;
        }

        $mateShare->setDeliveryGroup($source->getDeliveryGroup());
        $mateShare->setDayMonthOrder($source->getDayMonthOrder());
        $this->em->flush();

        // El par también reparte en viernes distintos hasta ahora: sus semanas
        // ya generadas hay que rehacerlas al turno nuevo, igual que las del socio.
        $this->generator->reconcilePartnerFrom($mate, $from);

        return $mate;
    }
}
