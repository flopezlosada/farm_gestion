<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\AppSettings;

/**
 * Fuente ÚNICA de la política de plazos del cambio puntual de reparto.
 *
 * Calcula el deadline (fecha + hora límite) para que un socix pida o revierta
 * un cambio sobre un Basket: la antelación en días y la hora de cierre salen de
 * {@see AppSettings} (configurables en /gestion/configuracion), y el día base es
 * la fecha FÍSICA del reparto del nodo del socix (vía {@see NodeDeliveryDate}),
 * no el viernes-ciclo — así Madrid (recogida en miércoles) tiene su plazo y no
 * el del viernes.
 *
 * La usan tanto {@see \App\Service\Delivery\Rule\DeadlineRule} (validador del
 * motor de reglas, lado admin) como {@see \App\Controller\PanelController} (gate
 * server-side del autoservicio), para que admin y socix vean el MISMO plazo.
 */
final class DeliveryDeadline
{
    public function __construct(
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Deadline efectivo para que $partner toque su reparto en $basket, o null
     * si no hay plazo aplicable: el Basket no tiene fecha, o el nodo del socix
     * no reparte ese Basket (cadencia quincenal fuera de fase). Cada caller
     * decide qué significa ese null — el validador lo trata como "sin regla que
     * aplicar" (permite); el panel, como "no editable" (bloquea).
     *
     * @param Partner $partner Socix que quiere el cambio.
     * @param Basket  $basket  Cesta-ciclo sobre la que se actúa.
     */
    public function forPartnerAndBasket(Partner $partner, Basket $basket): ?\DateTimeImmutable
    {
        $physicalDate = $this->physicalDateFor($basket, $partner);
        if ($physicalDate === null) {
            return null;
        }

        $daysBefore = $this->settings->getInt(AppSettings::DEADLINE_DAYS_BEFORE);
        [$hour, $minute] = $this->closingTime();

        return $physicalDate
            ->modify(sprintf('-%d day', $daysBefore))
            ->setTime($hour, $minute, 0);
    }

    /**
     * ¿Sigue abierto el plazo para que $partner cambie $basket? Un deadline
     * nulo (sin plazo aplicable) se considera CERRADO: sin fecha física no se
     * deja actuar a ciegas desde el autoservicio.
     */
    public function isOpen(Partner $partner, Basket $basket): bool
    {
        $deadline = $this->forPartnerAndBasket($partner, $basket);

        return $deadline !== null && new \DateTimeImmutable('now') < $deadline;
    }

    /**
     * Fecha física del reparto: la del nodo del socix si está configurado;
     * si no (datos pre-Node), el propio viernes-ciclo del Basket.
     */
    private function physicalDateFor(Basket $basket, Partner $partner): ?\DateTimeImmutable
    {
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node !== null) {
            return $this->nodeDeliveryDate->physicalDateFor($basket, $node);
        }

        $date = $basket->getDate();
        if ($date === null) {
            return null;
        }

        return $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);
    }

    /**
     * Hora de cierre configurada, partida en [hora, minuto]. AppSettings ya
     * garantiza un "HH:MM" válido (cae al default si no), así que el split es
     * seguro.
     *
     * @return array{0: int, 1: int}
     */
    private function closingTime(): array
    {
        [$hour, $minute] = explode(':', $this->settings->getTime(AppSettings::DEADLINE_TIME));

        return [(int) $hour, (int) $minute];
    }
}
