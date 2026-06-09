<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambio puntual de LUGAR de recogida: un socio recoge su cesta de UNA semana en un
 * grupo/nodo distinto al suyo (p. ej. le toca Midori —miércoles, quincenal— y esa semana
 * la recoge en Torremocha —viernes, semanal—). Es un ajuste del eje ESPACIAL (nodo), no
 * del viernes (eso lo cubre PartnerDeliveryShift).
 *
 * Modelado como OVERRIDE ({@see PartnerNodeOverride}), hermano de PartnerDeliveryShift:
 * la fuente de verdad es el override de esa semana, que la PROYECCIÓN aplica al dibujar
 * (semanas futuras, sin materializar) y la MATERIALIZACIÓN aplica al congelar. Por eso
 * funciona también en semanas futuras dibujadas (no requiere WeeklyBasket que mover) y no
 * deja piedra parcial. Ver docs/redesign/modelo-overrides-reparto.md.
 *
 * Cascada a PIEDRA: si la semana ya está materializada para el socio (tiene WeeklyBasket),
 * se mueve ese WB al grupo destino y se recalcula su fecha física, para que el listado de
 * piedra refleje el override. Si la semana se DIBUJA (sin WB), basta el override.
 *
 * El cambio es de SOLO esa semana (one_off): nunca toca Partner.weekly_basket_group (el
 * grupo de casa permanente).
 */
final class PickupRelocator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Registra el override de nodo del socio para esa semana (creándolo o re-apuntándolo),
     * cascadea a piedra si la semana ya está materializada y registra el evento.
     * Transaccional: hace flush al final.
     *
     * @param Partner           $partner Socio.
     * @param Basket            $basket  Semana/ciclo de reparto.
     * @param WeeklyBasketGroup $toGroup Grupo de recogida destino (de otro nodo).
     * @param string|null       $actor   Quién origina (ver PartnerEvent::$actor).
     * @return PartnerNodeOverride|null El override ya persistido, o null si el destino era el
     *                                  propio nodo de casa (se gestionó como vuelta al patrón).
     * @throws \LogicException Si el grupo destino no tiene nodo, si ya está trasladado a ese
     *                         grupo, o si el nodo destino no reparte esa semana.
     */
    public function relocate(Partner $partner, Basket $basket, WeeklyBasketGroup $toGroup, ?string $actor = null): ?PartnerNodeOverride
    {
        $destNode = $toGroup->getNode();
        if ($destNode === null) {
            throw new \LogicException('El grupo de destino no tiene un nodo de recogida asociado.');
        }
        $homeGroup = $partner->getWeeklyBasketGroup();
        if ($homeGroup?->getNode()?->getId() === $destNode->getId()) {
            // Trasladar al propio nodo = volver al PATRÓN. Se gestiona como returnHome: borra
            // el override y restaura el grupo de casa REAL del socio, no el grupo representativo
            // que llega en $toGroup (la lista genérica manda un grupo cualquiera del nodo). Así
            // "volver a su nodo" funciona también desde la lista de "recoger en otro nodo".
            $this->returnHome($partner, $basket, $actor);

            return null;
        }
        if (!$this->nodeDeliveryDate->deliversInBasket($basket, $destNode)) {
            throw new \LogicException('El nodo de destino no reparte esa semana.');
        }

        // Override = fuente de verdad. Crear o re-apuntar el de esta semana.
        $overrideRepo = $this->em->getRepository(PartnerNodeOverride::class);
        $override = $overrideRepo->findForPartnerAndBasket($partner, $basket);
        if ($override !== null && $override->getTargetGroup()?->getId() === $toGroup->getId()) {
            throw new \LogicException('El socix ya recoge en ese grupo esa semana.');
        }
        if ($override === null) {
            $override = new PartnerNodeOverride($partner, $basket, $toGroup);
            $this->em->persist($override);
        } else {
            $override->setTargetGroup($toGroup);
        }

        // Cascada a PIEDRA solo si la semana ya está materializada para el socio: mover su
        // WeeklyBasket al grupo destino y recalcular la fecha física. Si la semana se DIBUJA
        // (sin WB), no se toca piedra: la proyección aplica el override (sin piedra parcial).
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb !== null) {
            $wb->setWeeklyBasketGroup($toGroup);
            $wb->setDeliveryDate($this->nodeDeliveryDate->physicalDateFor($basket, $destNode) ?? $basket->getDate());
        }

        $this->recordEvent($partner, $basket, $homeGroup, $toGroup, $actor);
        $this->em->flush();

        return $override;
    }

    /**
     * Devuelve al socio a SU nodo de casa esa semana: borra el override de nodo (la
     * desviación) y, si la semana está materializada, revierte su WeeklyBasket al grupo de
     * casa y recalcula la fecha física. Es la inversa de {@see relocate}: vuelve al PATRÓN,
     * por eso restaura {@see Partner::getWeeklyBasketGroup} (el grupo de casa real), no un
     * grupo representativo. Idempotente: si no hay override esa semana, no hace nada.
     * Transaccional: hace flush al final.
     *
     * @param Partner     $partner Socio trasladado.
     * @param Basket      $basket  Semana/ciclo de reparto.
     * @param string|null $actor   Quién origina (ver PartnerEvent::$actor).
     */
    public function returnHome(Partner $partner, Basket $basket, ?string $actor = null): void
    {
        $override = $this->em->getRepository(PartnerNodeOverride::class)->findForPartnerAndBasket($partner, $basket);
        if ($override === null) {
            return; // No estaba trasladado esa semana: nada que revertir.
        }

        $fromGroup = $override->getTargetGroup();
        $homeGroup = $partner->getWeeklyBasketGroup();
        $homeNode = $homeGroup?->getNode();

        $this->em->remove($override);

        // Cascada a PIEDRA: si la semana ya está materializada, devolver el WeeklyBasket al
        // grupo de casa y recalcular la fecha física del nodo de casa. Si se DIBUJA, basta
        // con haber borrado el override (la proyección vuelve a situarlo en su nodo).
        if ($homeGroup !== null) {
            $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
            if ($wb !== null) {
                $wb->setWeeklyBasketGroup($homeGroup);
                $wb->setDeliveryDate(
                    ($homeNode !== null ? $this->nodeDeliveryDate->physicalDateFor($basket, $homeNode) : null)
                    ?? $basket->getDate(),
                );
            }
            // Histórico: vuelta del grupo destino al grupo de casa (mismo formato que relocate).
            $this->recordEvent($partner, $basket, $fromGroup, $homeGroup, $actor);
        }

        $this->em->flush();
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
