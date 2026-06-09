<?php

namespace App\Service\Delivery;

use App\Entity\Partner;
use App\Repository\BasketRepository;
use App\Repository\WeeklyBasketGroupRepository;

/**
 * Opciones para el traslado puntual de nodo de un socix: las SEMANAS sobre las que
 * puede trasladar y los NODOS destino. Lo comparten la ficha del gestor
 * ({@see \App\Controller\PartnerController}) y el panel del socix
 * ({@see \App\Controller\PanelController}) para no duplicar la construcción de los
 * desplegables; la acción de trasladar vive en {@see PickupRelocator}.
 */
final class PickupRelocationOptions
{
    /** Ventana por defecto de semanas futuras ofrecibles (en semanas). */
    private const DEFAULT_WEEKS_AHEAD = 10;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly WeeklyBasketGroupRepository $groupRepository,
        private readonly WeeklyBasketGenerator $generator,
    ) {
    }

    /**
     * SEMANAS (Basket) a las que el socix puede trasladar su recogida: las PRÓXIMAS en las
     * que su patrón le da entrega (cohorte/cadencia/nodo), estén DIBUJADAS o ya generadas.
     * El traslado es un override ({@see \App\Entity\PartnerNodeOverride}), así que funciona
     * también sobre semanas futuras sin materializar — igual que la cesta extra; limitarlo a
     * las materializadas dejaba solo la semana en operación (materialización tardía). La
     * candidatura se decide con {@see WeeklyBasketGenerator::projectForBasket} (misma fuente
     * de verdad que el calendario), así que no se ofrecen las semanas alternas en las que un
     * quincenal NO recibe cesta. La etiqueta usa la fecha física proyectada del nodo de casa.
     *
     * @param Partner  $partner
     * @param int|null $weeksAhead Ventana hacia delante (semanas); null = por defecto.
     * @return list<array{basketId: int, date: \DateTimeInterface}> En orden cronológico.
     */
    public function weeksForPartner(Partner $partner, ?int $weeksAhead = null): array
    {
        $from = new \DateTime('today');
        $to = (clone $from)->modify(sprintf('+%d weeks', $weeksAhead ?? self::DEFAULT_WEEKS_AHEAD));

        $weeks = [];
        foreach ($this->basketRepository->findBetweenDates($from, $to) as $basket) {
            // ¿El patrón del socio le da entrega esta semana? Reusa projectForBasket (cohorte/
            // orden/nodo) en vez de replicar la regla: una sola fuente de verdad para "¿le toca?".
            $share = null;
            foreach ($this->generator->projectForBasket($basket) as $candidate) {
                if ($candidate->getPartner()->getId() === $partner->getId()) {
                    $share = $candidate;
                    break;
                }
            }
            if ($share === null) {
                continue;
            }

            $projection = $this->generator->projectShareDelivery($basket, $share);
            $weeks[] = [
                'basketId' => $basket->getId(),
                'date' => $projection['deliveryDate'] ?? $basket->getDate(),
            ];
        }

        return $weeks;
    }

    /**
     * NODOS a los que el socix puede trasladar su recogida: una opción por nodo distinto al
     * suyo, etiqueta = nombre del nodo. El `id` es un grupo REPRESENTATIVO del nodo destino
     * (el relocado sale en la sección "Trasladados", así que el grupo concreto es invisible;
     * basta uno para anclar el WeeklyBasket). No se filtra por "reparte esa semana" porque
     * la semana se elige aparte; si el par semana/nodo no encaja, {@see PickupRelocator} lo
     * rechaza con aviso. Ver docs/redesign/modelo-overrides-reparto.md.
     *
     * @param Partner $partner
     * @return list<array{id: int, label: string}> Ordenados por nombre de nodo.
     */
    public function groupsForPartner(Partner $partner): array
    {
        $currentNodeId = $partner->getWeeklyBasketGroup()?->getNode()?->getId();

        // Un grupo representativo por nodo (el primero por nombre), excluyendo el nodo de casa.
        $byNode = [];
        foreach ($this->groupRepository->findBy([], ['name' => 'ASC']) as $group) {
            $node = $group->getNode();
            if ($node === null || $node->getId() === $currentNodeId) {
                continue;
            }
            $byNode[$node->getId()] ??= ['id' => $group->getId(), 'label' => $node->getName()];
        }
        $options = array_values($byNode);
        usort($options, static fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $options;
    }
}
