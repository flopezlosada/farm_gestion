<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Service\Delivery\DeliveryCalendarProjector;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L14 — Calendario = listado: en cada semana YA LISTADA (con piedra), lo que el
 * calendario promete a cada socio es exactamente lo que el listado del nodo le
 * entrega.
 *
 * Son dos caminos de lectura distintos y por eso pueden divergir sin que nada
 * falle: el calendario (socix y gestión) sale de {@see DeliveryCalendarProjector},
 * que en una semana sin fila del socio PROYECTA su patrón; el PDF del listado sale
 * de las filas activas (estado 1) del nodo y sus ítems. Si una acción deja la
 * semana sin la fila que debería tener —o con una que no debería—, el socio ve una
 * cesta que nadie le va a preparar, o al revés. Caso real: deshacer un traslado
 * cuyo origen ya estaba congelado sin fila dejaba la cesta en el calendario y
 * fuera del listado.
 *
 * Compara composición (componente → cantidad) por socio y semana: los slots del
 * calendario que se recogen (ni saltados ni cerrados) contra las filas en estado 1
 * con cantidad positiva. La fecha física y el nodo los vigilan L9 y L15.
 */
final class CalendarMatchesSheetInvariant extends AbstractInvariant
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly DeliveryCalendarProjector $projector,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L14';
    }

    public function name(): string
    {
        return 'El calendario del socio coincide con el listado en las semanas ya listadas';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var Basket[] $listedBaskets */
        $listedBaskets = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b
             WHERE b.date >= :from AND EXISTS (
                 SELECT wb.id FROM ' . WeeklyBasket::class . ' wb WHERE wb.basket = b
             )
             ORDER BY b.date ASC'
        )->setParameter('from', $from)->getResult();
        if ($listedBaskets === []) {
            return [];
        }

        $basketsById = [];
        foreach ($listedBaskets as $basket) {
            $basketsById[$basket->getId()] = $basket;
        }
        $first = reset($listedBaskets)->getDate();
        $last = end($listedBaskets)->getDate();

        $sheet = $this->sheetCompositions(array_keys($basketsById));

        $violations = [];
        foreach ($this->partnersToCheck($first, array_keys($sheet)) as $partner) {
            $calendar = $this->calendarCompositions($partner, $first, $last, $basketsById);
            $listed = $sheet[$partner->getId()] ?? [];
            foreach (array_keys($calendar + $listed) as $basketId) {
                $promised = $calendar[$basketId] ?? [];
                $delivered = $listed[$basketId] ?? [];
                if ($promised == $delivered) {
                    continue;
                }
                $violations[] = sprintf(
                    '%s (%d), semana %s: el calendario promete %s y el listado entrega %s.',
                    $partner->getLegalName(),
                    $partner->getId(),
                    $this->d($basketsById[$basketId]->getDate()),
                    $this->describe($promised),
                    $this->describe($delivered),
                );
            }
        }

        return $violations;
    }

    /**
     * Lo que el listado entrega: por socio y semana, la suma por componente de
     * las filas activas (estado 1, lo mismo que lee el PDF) con cantidad positiva.
     *
     * @param list<int> $basketIds Semanas listadas a evaluar.
     * @return array<int, array<int, array<int, float>>> partnerId => basketId => componentId => cantidad
     */
    private function sheetCompositions(array $basketIds): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT w.basket_id, w.partner_id, i.basket_component_id AS component_id, i.amount
             FROM weekly_basket w
             JOIN weekly_basket_item i ON i.weekly_basket_id = w.id AND i.amount > 0
             WHERE w.basket_id IN (:baskets) AND w.weekly_basket_status_id = 1',
            ['baskets' => $basketIds],
            ['baskets' => ArrayParameterType::INTEGER],
        );

        $sheet = [];
        foreach ($rows as $row) {
            $partnerId = (int) $row['partner_id'];
            $basketId = (int) $row['basket_id'];
            $sheet[$partnerId][$basketId] ??= [];
            $this->add($sheet[$partnerId][$basketId], (int) $row['component_id'], (float) $row['amount']);
        }

        return $sheet;
    }

    /**
     * Lo que el calendario promete al socio en las semanas listadas, por semana.
     * Un mismo día puede tener dos slots (la cesta aparcada en la papelera y otra
     * movida a él); sólo cuentan los que se recogen.
     *
     * @param Partner                $partner
     * @param \DateTimeInterface     $first       Primera semana listada.
     * @param \DateTimeInterface     $last        Última semana listada.
     * @param array<int, Basket>     $basketsById Semanas listadas.
     * @return array<int, array<int, float>> basketId => componentId => cantidad
     */
    private function calendarCompositions(Partner $partner, \DateTimeInterface $first, \DateTimeInterface $last, array $basketsById): array
    {
        $calendar = [];
        $month = \DateTimeImmutable::createFromInterface($first)->modify('first day of this month');
        while ($month <= $last) {
            foreach ($this->projector->projectMonth($partner, (int) $month->format('Y'), (int) $month->format('n')) as $slot) {
                $basketId = $slot['basket']->getId();
                if (!isset($basketsById[$basketId]) || $slot['skipped'] || ($slot['closed'] ?? false)) {
                    continue;
                }
                foreach ($slot['items'] as $item) {
                    if ((float) $item['amount'] > 0) {
                        $calendar[$basketId] ??= [];
                        $this->add($calendar[$basketId], $item['component']->getId(), (float) $item['amount']);
                    }
                }
            }
            $month = $month->modify('+1 month');
        }

        return $calendar;
    }

    /**
     * Socios a evaluar: los que tienen una suscripción que llega a la ventana
     * listada (el calendario podría prometerles algo) y los que tienen fila en el
     * listado (podrían recibir algo que el calendario no promete). Los miembros de
     * familia no tienen calendario propio (L5F).
     *
     * @param \DateTimeInterface $first     Primera semana listada.
     * @param list<int>          $listedIds Socios con fila en el listado.
     * @return Partner[]
     */
    private function partnersToCheck(\DateTimeInterface $first, array $listedIds): array
    {
        return $this->em->createQuery(
            'SELECT p FROM ' . Partner::class . ' p
             WHERE p.parent IS NULL AND (
                 p.id IN (:listed) OR EXISTS (
                     SELECT pbs.id FROM ' . PartnerBasketShare::class . ' pbs
                     WHERE pbs.partner = p AND (pbs.end_date IS NULL OR pbs.end_date >= :first)
                 )
             )
             ORDER BY p.id ASC'
        )->setParameter('listed', $listedIds === [] ? [0] : $listedIds)
            ->setParameter('first', $first)
            ->getResult();
    }

    /**
     * Suma una cantidad redondeada a céntimos, para que 0.5 + 0.5 cuadre con 1.
     *
     * @param array<int, float> $composition
     * @param int               $componentId
     * @param float             $amount
     */
    private function add(array &$composition, int $componentId, float $amount): void
    {
        $composition[$componentId] = round(($composition[$componentId] ?? 0.0) + $amount, 2);
        ksort($composition);
    }

    /**
     * Composición legible para el informe ("verdura 1, huevos 0.5" o "nada").
     *
     * @param array<int, float> $composition
     * @return string
     */
    private function describe(array $composition): string
    {
        if ($composition === []) {
            return 'nada';
        }

        return implode(', ', array_map(
            static fn (int $id, float $amount): string => sprintf('%s %s', match ($id) {
                BasketComponent::ID_VEGETABLES => 'verdura',
                BasketComponent::ID_EGGS => 'huevos',
                default => 'componente ' . $id,
            }, $amount),
            array_keys($composition),
            $composition,
        ));
    }
}
