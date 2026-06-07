<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambio puntual de LUGAR de recogida: un socio recoge su cesta de UNA semana en un
 * grupo/nodo distinto al suyo (p. ej. le toca Midori —miércoles, quincenal— y esa semana
 * la recoge en Torremocha —viernes, semanal—). Es un ajuste del eje ESPACIAL (nodo), no
 * del viernes (eso lo cubre PartnerDeliveryShift).
 *
 * Lo que ata una entrega a un nodo es WeeklyBasket.weekly_basket_group.node: el listado
 * de un nodo se arma con WeeklyBasketRepository::findForNodeAndBasket filtrando por
 * wbg.node. Por eso basta cambiar el grupo del WeeklyBasket de esa semana —y recalcular
 * su fecha física con el nodo destino— para que el listado del destino lo absorba y el
 * del origen lo excluya, sin tocar el generador ni los findOneBy del reparto.
 *
 * v1: solo TRASLADA una entrega que ya existe esa semana (el socio tiene cesta). No crea
 * entregas donde no le tocaba (eso cruza con la cesta extra, ver ExtraBasketEditor).
 *
 * El cambio es de SOLO esa semana (one_off): se altera el WeeklyBasket, nunca
 * Partner.weekly_basket_group (que es el grupo por defecto, permanente).
 */
final class PickupRelocator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Traslada la entrega del socio en esa semana al grupo/nodo destino, recalcula su
     * fecha física y registra el evento. Transaccional: hace flush al final.
     *
     * @param Partner           $partner Socio.
     * @param Basket            $basket  Semana/ciclo de reparto.
     * @param WeeklyBasketGroup $toGroup Grupo de recogida destino (de cualquier nodo).
     * @param string|null       $actor   Quién origina (ver PartnerEvent::$actor).
     * @return WeeklyBasket La entrega ya trasladada.
     * @throws \LogicException Si el socio no tiene cesta esa semana, si el grupo destino
     *                         no tiene nodo, si ya recoge ahí, o si el nodo destino no
     *                         reparte esa semana.
     */
    public function relocate(Partner $partner, Basket $basket, WeeklyBasketGroup $toGroup, ?string $actor = null): WeeklyBasket
    {
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb === null) {
            throw new \LogicException('Este socix no tiene cesta esa semana que trasladar a otro nodo.');
        }

        $previousGroup = $wb->getWeeklyBasketGroup();
        if ($previousGroup !== null && $previousGroup->getId() === $toGroup->getId()) {
            throw new \LogicException('El socix ya recoge en ese grupo esa semana.');
        }

        $node = $toGroup->getNode();
        if ($node === null) {
            throw new \LogicException('El grupo de destino no tiene un nodo de recogida asociado.');
        }
        if (!$this->nodeDeliveryDate->deliversInBasket($basket, $node)) {
            throw new \LogicException('El nodo de destino no reparte esa semana.');
        }

        $wb->setWeeklyBasketGroup($toGroup);
        $wb->setDeliveryDate($this->nodeDeliveryDate->physicalDateFor($basket, $node) ?? $basket->getDate());

        $this->recordEvent($partner, $basket, $previousGroup, $toGroup, $actor);
        $this->em->flush();

        return $wb;
    }

    /**
     * Registra el traslado puntual en el histórico del socio (PartnerEvent NODE_CHANGE
     * con scope one_off), mismo formato que el autoservicio del panel.
     *
     * @param Partner                $partner
     * @param Basket                 $basket
     * @param WeeklyBasketGroup|null $from
     * @param WeeklyBasketGroup      $to
     * @param string|null            $actor
     */
    private function recordEvent(Partner $partner, Basket $basket, ?WeeklyBasketGroup $from, WeeklyBasketGroup $to, ?string $actor): void
    {
        $event = new PartnerEvent($partner, PartnerEvent::TYPE_NODE_CHANGE);
        $event->setPayload([
            'scope' => 'one_off',
            'basket_id' => $basket->getId(),
            'from_group_id' => $from?->getId(),
            'from_group_name' => $from?->getName(),
            'to_group_id' => $to->getId(),
            'to_group_name' => $to->getName(),
        ]);
        if ($actor !== null) {
            $event->setActor($actor);
        }
        $this->em->persist($event);
    }
}
