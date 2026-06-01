<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
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
        private readonly WeeklyBasketComposer $composer,
        private readonly EggDeliveryResolver $eggResolver,
    ) {
    }

    /**
     * Cada slot lleva, además de los HECHOS de la entrega, dos datos que la
     * pantalla necesita para pintar las acciones de B' (saltar, editar por
     * contenido) sin re-derivar política:
     *  - 'skipped': true si la entrega está materializada y marcada como NO
     *    recogida (estado 2). Las proyectadas nunca están saltadas (son el
     *    patrón por defecto).
     *  - 'available': los BasketComponent que el patrón del socio contempla esa
     *    semana (lo que computeItems estamparía). Es el universo de toggles
     *    posibles: un componente presente se puede quitar, uno ausente-pero-
     *    disponible se puede añadir. Un componente que el patrón no da esa
     *    semana (p. ej. huevos en una semana sin huevo) no aparece.
     *
     * @param Partner $partner Socio cuyo calendario se proyecta.
     * @param int     $year    Año (p. ej. 2026).
     * @param int     $month   Mes 1-12.
     * @return list<array{date: \DateTimeInterface, basket: Basket, source: 'materialized'|'projected', weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>, skipped: bool, moved_away: bool, listed: bool, available: list<BasketComponent>}>
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
        $shiftRepo = $this->em->getRepository(\App\Entity\PartnerDeliveryShift::class);

        $slots = [];
        foreach ($baskets as $basket) {
            // ¿El listado de esta semana ya está generado (hay WB de algún socio)?
            // La pantalla NO deja editar contenido en semanas sin generar: materializar
            // un WB suelto dispararía la rama reuseExistingWeeklyBaskets del generador y
            // dejaría fuera al resto de socios. Mover sí es seguro (solo guarda el shift).
            $listed = $wbRepo->findOneBy(['basket' => $basket->getId()]) !== null;
            $materialized = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($materialized !== null) {
                $skipped = $materialized->getWeeklyBasketStatus()?->getId() === WeeklyBasketSkipper::STATUS_SKIPPED;
                $slots[] = [
                    'date' => $materialized->getDeliveryDate() ?? $basket->getDate(),
                    'basket' => $basket,
                    'source' => 'materialized',
                    'weeklyBasket' => $materialized,
                    'items' => $this->readItems($materialized),
                    'skipped' => $skipped,
                    // Vaciado por un movimiento (no "no recoge" de verdad): la pantalla
                    // lo pinta distinto. Es saltado Y tiene un cambio saliendo de aquí.
                    'moved_away' => $skipped && $shiftRepo->findOutgoing($partner, $basket) !== null,
                    'listed' => $listed,
                    'available' => $this->availableComponents($materialized, $basket),
                ];
                continue;
            }

            // Sin materializar: el patrón puro NO refleja los cambios puntuales, así
            // que aquí se aplican igual que el generador del reparto, o el calendario
            // mostraría entregas fantasma en los días vaciados por un movimiento.

            // Cesta MOVIDA aquí desde otra fecha (cambio entrante aún sin materializar):
            // se proyecta con la modalidad del origen. Su composición es la REAL del
            // ORIGEN si está materializado (conserva lo quitado a mano); si no, el
            // patrón con los huevos del origen. Igual que hará el generador al
            // materializar — si no, la vista previa mostraría componentes que la cesta
            // ya no lleva.
            $incoming = $shiftRepo->findIncoming($partner, $basket);
            if ($incoming !== null) {
                $origin = $incoming->getFromBasket();
                $movedShare = $this->candidateShareFor($partner, $origin)
                    ?? $this->em->getRepository(\App\Entity\PartnerBasketShare::class)->findActiveForPartner($partner, $origin->getDate());
                $projection = $movedShare !== null
                    ? $this->generator->projectShareDelivery($basket, $movedShare, $origin)
                    : null;
                if ($projection !== null) {
                    $sourceWb = $wbRepo->findOneBy(['basket' => $origin->getId(), 'partner' => $partner->getId()]);
                    $items = $sourceWb !== null ? $this->composer->copyLines($sourceWb) : $projection['items'];
                    $slots[] = [
                        'date' => $projection['deliveryDate'],
                        'basket' => $basket,
                        'source' => 'projected',
                        'weeklyBasket' => $projection['weeklyBasket'],
                        'items' => $items,
                        'skipped' => false,
                        'moved_away' => false,
                        'listed' => $listed,
                        'available' => array_map(static fn (array $line): BasketComponent => $line['component'], $items),
                    ];
                }
                continue;
            }

            // Cesta VACIADA de aquí (cambio saliente): su cesta está en otra fecha.
            // Día sin entrega → se pinta como "no recoge" (rojo) y sigue siendo
            // seleccionable para ver a dónde se movió.
            $outgoing = $shiftRepo->findOutgoing($partner, $basket);
            if ($outgoing !== null) {
                $patternShare = $this->candidateShareFor($partner, $basket);
                $physical = $patternShare !== null
                    ? ($this->generator->projectShareDelivery($basket, $patternShare)['deliveryDate'] ?? $basket->getDate())
                    : $basket->getDate();
                $slots[] = [
                    'date' => $physical,
                    'basket' => $basket,
                    'source' => 'projected',
                    'weeklyBasket' => (new WeeklyBasket())->setBasket($basket)->setPartner($partner),
                    'items' => [],
                    'skipped' => true,
                    'moved_away' => true,
                    'listed' => $listed,
                    'available' => [],
                ];
                continue;
            }

            $share = $this->candidateShareFor($partner, $basket);
            if ($share === null) {
                // Sin cesta candidata esta semana: ¿le toca huevo-extra? (cesta mensual/
                // quincenal con huevo MÁS frecuente que la cesta — SANTOS semanal de huevo,
                // MIRIAM…). Espeja materializeExtraEggDeliveries del generador en modo dry;
                // sin esto, las semanas de solo-huevo de esos socios no aparecían en el
                // calendario de meses aún sin generar.
                $eggSlot = $this->projectExtraEgg($partner, $basket, $listed);
                if ($eggSlot !== null) {
                    $slots[] = $eggSlot;
                }
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
                // Proyectada = patrón por defecto: nunca saltada, y lo presente
                // coincide con lo disponible (nada se ha quitado todavía).
                'skipped' => false,
                'moved_away' => false,
                'listed' => $listed,
                'available' => array_map(static fn (array $line): BasketComponent => $line['component'], $projection['items']),
            ];
        }

        // Reflejar los intents POR COMPONENTE (dry): en origen quita el componente de
        // la entrega de esa semana; en destino lo añade (creando un slot si esa semana
        // no tenía entrada proyectada). Es lo que muestra el plan de SANTOS antes de
        // generar. Va al final, cuando ya están todos los slots base (incluido el
        // huevo-extra). Los intents de entrega ENTERA se reflejan en el bucle de arriba.
        return $this->applyComponentIntentsToSlots($partner, $baskets, $slots);
    }

    /**
     * Aplica los intents POR COMPONENTE sobre los slots ya proyectados.
     *
     * @param Basket[]                                                          $baskets
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}>        $slots
     * @return list<array{date: \DateTimeInterface, basket: Basket, ...}>
     */
    private function applyComponentIntentsToSlots(Partner $partner, array $baskets, array $slots): array
    {
        /** @var \App\Repository\PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(\App\Entity\PartnerDeliveryShift::class);
        $shareRepo = $this->em->getRepository(\App\Entity\PartnerBasketShare::class);

        // Índice slot por basket id (para localizar el slot de cada intent).
        $idxByBasket = [];
        foreach ($slots as $i => $slot) {
            $idxByBasket[$slot['basket']->getId()] = $i;
        }

        foreach ($baskets as $basket) {
            $out = $shiftRepo->findComponentIntentsFromBasket($partner, $basket);
            $in = $shiftRepo->findComponentIntentsToBasket($partner, $basket);
            if ($out === [] && $in === []) {
                continue;
            }
            $idx = $idxByBasket[$basket->getId()] ?? null;

            // ORIGEN: quitar el componente de la entrega de esa semana.
            foreach ($out as $intent) {
                if ($idx === null) {
                    continue;
                }
                $cid = $intent->getComponent()->getId();
                $slots[$idx]['items'] = array_values(array_filter(
                    $slots[$idx]['items'],
                    static fn (array $line): bool => $line['component']->getId() !== $cid,
                ));
                $slots[$idx]['available'] = array_values(array_filter(
                    $slots[$idx]['available'],
                    static fn (BasketComponent $c): bool => $c->getId() !== $cid,
                ));
            }

            // DESTINO: añadir el componente (creando slot si no había entrega esa semana).
            foreach ($in as $intent) {
                $component = $intent->getComponent();
                $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
                if ($share === null) {
                    continue;
                }
                $amount = $this->composer->amountForComponent($share, $component);
                if ($amount === null) {
                    continue;
                }

                if ($idx === null) {
                    $projection = $this->generator->projectShareDelivery($basket, $share);
                    if ($projection === null) {
                        continue; // el nodo no reparte esa semana
                    }
                    $slots[] = [
                        'date' => $projection['deliveryDate'],
                        'basket' => $basket,
                        'source' => 'projected',
                        'weeklyBasket' => $projection['weeklyBasket'],
                        'items' => [['component' => $component, 'amount' => $amount]],
                        'skipped' => false,
                        'moved_away' => false,
                        'listed' => $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket->getId()]) !== null,
                        'available' => [$component],
                    ];
                    $idx = array_key_last($slots);
                    $idxByBasket[$basket->getId()] = $idx;
                    continue;
                }

                $alreadyHas = false;
                foreach ($slots[$idx]['items'] as $line) {
                    if ($line['component']->getId() === $component->getId()) {
                        $alreadyHas = true;
                        break;
                    }
                }
                if (!$alreadyHas) {
                    $slots[$idx]['items'][] = ['component' => $component, 'amount' => $amount];
                }
                $availHas = false;
                foreach ($slots[$idx]['available'] as $c) {
                    if ($c->getId() === $component->getId()) {
                        $availHas = true;
                        break;
                    }
                }
                if (!$availHas) {
                    $slots[$idx]['available'][] = $component;
                }
            }
        }

        return $slots;
    }

    /**
     * Componentes que el patrón del socio contempla en esta entrega
     * materializada (lo que computeItems estamparía esta semana): el universo de
     * toggles posibles, ESTABLE e independiente de lo que la entrega lleve ahora
     * mismo. Es clave que no dependa de los ítems presentes: si al quitar huevos
     * 'available' encogiera, el interruptor desaparecería y no se podrían volver
     * a añadir.
     *
     * El share se toma del WB; si no lo tiene (datos previos al backfill de Etapa
     * 1), se busca el share activo del socio en esa fecha. Solo si tampoco existe
     * se degrada a lo presente — no se inventa un patrón.
     *
     * @param WeeklyBasket $weeklyBasket Entrega materializada.
     * @param Basket       $basket       Ciclo semanal de la entrega.
     * @return list<BasketComponent>
     */
    private function availableComponents(WeeklyBasket $weeklyBasket, Basket $basket): array
    {
        $share = $weeklyBasket->getPartnerBasketShare();
        if ($share === null && $weeklyBasket->getPartner() !== null) {
            $share = $this->em->getRepository(\App\Entity\PartnerBasketShare::class)
                ->findActiveForPartner($weeklyBasket->getPartner(), $basket->getDate());
        }

        $lines = $share !== null
            ? $this->composer->computeItems($weeklyBasket, $share, $basket)
            : $this->readItems($weeklyBasket);

        return array_map(static fn (array $line): BasketComponent => $line['component'], $lines);
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
     * Proyecta (dry) la entrega de SOLO HUEVO que un socio recibe una semana en la
     * que no le toca cesta pero sí huevo (huevo más frecuente que la cesta). Espejo
     * de WeeklyBasketGenerator::materializeExtraEggDeliveries sin persistir. Null si
     * el socio no tiene huevos, no le toca esa semana, o su nodo no reparte.
     *
     * @return array{date: \DateTimeInterface, basket: Basket, source: 'projected', weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>, skipped: bool, moved_away: bool, listed: bool, available: list<BasketComponent>}|null
     */
    private function projectExtraEgg(Partner $partner, Basket $basket, bool $listed): ?array
    {
        $share = $this->em->getRepository(\App\Entity\PartnerBasketShare::class)
            ->findActiveForPartner($partner, $basket->getDate());
        if ($share === null || $share->getEggAmount() === null) {
            return null;
        }
        if (!$this->eggResolver->delivers($share, $basket)) {
            return null;
        }
        $projection = $this->generator->projectShareDelivery($basket, $share);
        if ($projection === null) {
            return null; // el nodo del socio no reparte esa semana
        }

        $eggs = $this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        $amount = $eggs !== null ? $this->composer->amountForComponent($share, $eggs) : null;
        if ($eggs === null || $amount === null) {
            return null;
        }

        return [
            'date' => $projection['deliveryDate'],
            'basket' => $basket,
            'source' => 'projected',
            'weeklyBasket' => $projection['weeklyBasket'],
            'items' => [['component' => $eggs, 'amount' => $amount]],
            'skipped' => false,
            'moved_away' => false,
            'listed' => $listed,
            'available' => [$eggs],
        ];
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
