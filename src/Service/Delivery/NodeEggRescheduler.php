<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Operación en LOTE sobre los huevos de un reparto: retira las docenas de un
 * (semana, nodo) para TODOS los socios que las llevaban y, opcionalmente, las
 * SUMA a otro reparto — ese día pasa a llevar el doble.
 *
 * Nace de una petición de administración: cuando una semana no hay huevos
 * suficientes (muda de las gallinas, incidencia en la granja) hay que quitarlos
 * de un punto de recogida entero, o recolocarlos en el reparto siguiente. Hasta
 * ahora sólo se podía hacer socio a socio desde el calendario.
 *
 * DOS OPERACIONES, UN SOLO CAMINO: trasladar es "quitar del origen" + "sumar al
 * destino". Quitar sin más es exactamente lo mismo con `$to = null`. Por eso no
 * hay dos servicios ni dos ramas de escritura, sólo un destino opcional.
 *
 * Piezas reutilizadas (nada de esto es nuevo):
 *  - Enumerar a quién le tocan huevos: la misma decisión piedra-vs-dibujo que el
 *    listado de reparto ({@see DeliveryModeResolver}), leyendo de los
 *    WeeklyBasket materializados o de {@see WeeklyBasketGenerator::projectDeliveriesForNode}.
 *    NO se listan los socios del nodo: se listan los que ESE reparto lleva huevos,
 *    que es distinto (un quincenal fuera de turno, un mensual que no le toca o
 *    alguien que ya usó su interruptor no están).
 *  - Quitar: intent durable por componente ({@see PartnerDeliverySkipper::applySkipIntent})
 *    + retirada de la línea si la semana ya está en piedra.
 *  - Sumar: {@see ExtraBasketAdder::addToDelivery}, que es aditivo de verdad
 *    (el destino acumula sobre lo que ya llevaba).
 *
 * ORDEN DELIBERADO, igual que en {@see AccumulatingMove}: primero se suma al
 * destino y después se vacía el origen, para que un fallo a medias deje docenas
 * de MÁS (visibles en el listado) y nunca de menos. El llamante lo envuelve
 * además en una transacción.
 *
 * RASTRO: el intent por componente que deja el vaciado es lo que permite a
 * {@see \App\Service\Delivery\Invariant\EggMonthlyConservationInvariant} (L17)
 * descontar esas docenas de lo esperado del mes. Sin él, cada socio tocado
 * aparecería como violación de la conservación mensual. Por eso el intent se
 * crea SIEMPRE, también cuando la semana está materializada y bastaría con
 * borrar la línea.
 *
 * PLAZO: se permite operar sobre el reparto de HOY (a diferencia del calendario
 * del socio, que corta el día anterior a la recogida). Es deliberado: quien usa
 * esto es gestión, y el motivo — no hay huevos — se descubre la misma mañana del
 * reparto. Lo que no se puede tocar es un reparto ya pasado.
 */
final class NodeEggRescheduler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeliveryModeResolver $modeResolver,
        private readonly WeeklyBasketGenerator $generator,
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly WeeklyBasketItemRepository $weeklyBasketItemRepo,
        private readonly PartnerDeliveryShiftRepository $shiftRepo,
        private readonly PartnerDeliverySkipper $skipper,
        private readonly ExtraBasketAdder $extraAdder,
        private readonly WeeklyBasketComposer $composer,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Socios que llevan huevos en ese reparto y cuántas docenas, por nodo.
     *
     * Es la previsualización de la pantalla y también la lista sobre la que
     * opera {@see apply}: se calcula una sola vez y de la misma forma, para que
     * lo que el gestor confirma sea exactamente lo que se ejecuta.
     *
     * @param Basket $from  Semana del reparto afectado.
     * @param Node[] $nodes Puntos de recogida seleccionados.
     * @return list<array{partner: Partner, node: Node, dozens: float}> Ordenado
     *         por nodo y nombre de reparto.
     */
    public function affected(Basket $from, array $nodes): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            foreach ($this->eggAmountsForNode($from, $node) as $entry) {
                $rows[] = ['partner' => $entry['partner'], 'node' => $node, 'dozens' => $entry['dozens']];
            }
        }

        usort($rows, static fn (array $a, array $b): int
            => ($a['node']->getName() <=> $b['node']->getName())
            ?: strnatcasecmp($a['partner']->getNameForDelivery() ?? '', $b['partner']->getNameForDelivery() ?? ''));

        return $rows;
    }

    /**
     * Ejecuta la operación sobre todos los afectados.
     *
     * @param Basket      $from  Semana de la que se retiran los huevos.
     * @param Node[]      $nodes Puntos de recogida afectados.
     * @param Basket|null $to    Semana a la que se trasladan, o null para
     *                           retirarlos sin recolocar (esas docenas se pierden).
     * @param string      $actor Quién lo origina (ver PartnerEvent::$actor).
     * @return array{moved: int, removed: int, dozens: float, skipped: list<string>}
     *         Cuántos socios se trasladaron / se quedaron sin huevos, el total de
     *         docenas movidas y los casos que se dejaron intactos, con su motivo.
     * @throws \InvalidArgumentException Si el reparto de origen ya pasó, si el
     *         destino no es posterior a hoy, o si origen y destino coinciden.
     */
    public function apply(Basket $from, array $nodes, ?Basket $to, string $actor): array
    {
        $this->assertOperable($from, $nodes, $to);

        $eggs = $this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        if ($eggs === null) {
            throw new \InvalidArgumentException('No se pudo resolver el componente huevos.');
        }

        $result = ['moved' => 0, 'removed' => 0, 'dozens' => 0.0, 'skipped' => []];
        $blockedBySemana = $this->existingShiftsFrom($from);

        foreach ($this->affected($from, $nodes) as $row) {
            $partner = $row['partner'];
            $reason = $blockedBySemana[$partner->getId()] ?? $this->destinationBlockerFor($partner, $to);
            if ($reason !== null) {
                $result['skipped'][] = sprintf('%s: %s', $partner->getNameForDelivery(), $reason);
                continue;
            }

            // 1. SUMAR al destino (si lo hay) ANTES de vaciar el origen.
            if ($to !== null) {
                $this->extraAdder->addToDelivery(
                    $partner,
                    $to,
                    [BasketComponent::ID_EGGS => (string) $row['dozens']],
                    sprintf('Huevos trasladados desde el %s', $from->getDate()?->format('d/m/Y') ?? '?'),
                    $actor,
                );
            }

            // 2. Vaciar el origen: intent durable (rastro para L17) y, si la
            //    semana está en piedra, retirada de la línea materializada.
            $this->skipper->applySkipIntent($partner, $from, $eggs, $actor);
            $this->removeMaterializedEggs($partner, $from, $eggs);

            $result[$to !== null ? 'moved' : 'removed']++;
            $result['dozens'] += $row['dozens'];
        }

        $this->em->flush();

        return $result;
    }

    /**
     * Semanas futuras a las que se puede trasladar el reparto: aquellas en las
     * que TODOS los nodos seleccionados tienen reparto operativo.
     *
     * La intersección es deliberada: con nodos de cadencia distinta (Torremocha
     * semanal, Midori quincenal) una semana suelta puede servir a uno y no al
     * otro, y un traslado que sólo funciona para media selección es peor que no
     * ofrecerlo. Si sale vacía, la pantalla lo dice y queda retirar sin recolocar
     * o seleccionar menos nodos.
     *
     * @param Basket $from    Semana de origen (se excluye de los destinos).
     * @param Node[] $nodes   Puntos de recogida seleccionados.
     * @param int    $horizon Cuántas semanas mirar hacia delante.
     * @return list<array{basket: Basket, dates: array<int, \DateTimeImmutable>}>
     *         Cada destino con la fecha física por nodo (id de nodo => fecha).
     */
    public function destinations(Basket $from, array $nodes, int $horizon = 8): array
    {
        if ($nodes === []) {
            return [];
        }

        $baskets = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b WHERE b.date > :today ORDER BY b.date ASC'
        )
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->setMaxResults($horizon)
            ->getResult();

        $destinations = [];
        foreach ($baskets as $basket) {
            if ($basket->getId() === $from->getId()) {
                continue;
            }
            $dates = [];
            foreach ($nodes as $node) {
                $date = $this->nodeDeliveryDate->operativeDateFor($basket, $node);
                if ($date === null) {
                    continue 2; // este nodo no reparte esa semana: no es destino común.
                }
                $dates[$node->getId()] = $date;
            }
            $destinations[] = ['basket' => $basket, 'dates' => $dates];
        }

        return $destinations;
    }

    /**
     * Socios que llevan huevos en un (semana, nodo) y cuántas docenas, leídos
     * de la misma fuente que el listado de reparto: la piedra si la semana está
     * materializada para ese nodo, el dibujo si aún no lo está.
     *
     * El Partner sale de la propia entrega (materializada o proyectada), no de
     * una búsqueda por id: son las mismas instancias que ya trajo la consulta
     * del listado, así que el lote no dispara una query por socio.
     *
     * @return array<int, array{partner: Partner, dozens: float}> Indexado por
     *         partner.id; sólo los que llevan huevos.
     */
    private function eggAmountsForNode(Basket $basket, Node $node): array
    {
        $mode = $this->modeResolver->mode($node, $basket);
        $found = [];

        if ($mode === DeliveryModeResolver::STONE) {
            $weeklyBaskets = $this->weeklyBasketRepo->findForNodeAndBasket($node, $basket);
            $amounts = $this->weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);
            foreach ($weeklyBaskets as $wb) {
                $partner = $wb->getPartner();
                $amount = $amounts[$wb->getId()][BasketComponent::ID_EGGS] ?? null;
                if ($partner !== null && $amount !== null && $amount > 0.0) {
                    $found[$partner->getId()] = ['partner' => $partner, 'dozens' => (float) $amount];
                }
            }

            return $found;
        }

        if ($mode !== DeliveryModeResolver::DRAW) {
            return $found; // el nodo no reparte esa semana, o está cancelada.
        }

        foreach ($this->generator->projectDeliveriesForNode($node, $basket) as $delivery) {
            $partner = $delivery['weeklyBasket']->getPartner();
            if ($partner === null) {
                continue;
            }
            foreach ($delivery['items'] as $item) {
                if ($item['component']->getId() === BasketComponent::ID_EGGS && (float) $item['amount'] > 0.0) {
                    $found[$partner->getId()] = ['partner' => $partner, 'dozens' => (float) $item['amount']];
                }
            }
        }

        return $found;
    }

    /**
     * Socios cuya semana de ORIGEN ya está gobernada por otro cambio, con el
     * motivo por el que el lote los deja intactos: pisarlos dejaría dos estados
     * a la vez sobre el mismo día (y, en el caso de los huevos, chocaría con el
     * único por (socio, semana, componente) de la BBDD).
     *
     * Se resuelve con UNA consulta para toda la semana, no una por socio.
     *
     * @param Basket $from Semana de origen.
     * @return array<int, string> partner.id => motivo.
     */
    private function existingShiftsFrom(Basket $from): array
    {
        $blocked = [];
        foreach ($this->shiftRepo->findAllOutgoingFromBasket($from) as $shift) {
            $pid = $shift->getPartner()?->getId();
            if ($pid === null) {
                continue;
            }
            if ($shift->isWholeDelivery()) {
                $blocked[$pid] = 'tiene un cambio de día activo esa semana';
            } elseif ($shift->getComponent()?->getId() === BasketComponent::ID_EGGS) {
                $blocked[$pid] ??= 'ya tiene un cambio de huevos esa semana';
            }
        }

        return $blocked;
    }

    /**
     * Motivo por el que un socio no puede recibir el traslado, o null si sí
     * puede. Sólo aplica cuando hay destino: sin él no hay nada que comprobar.
     *
     * Esto sí se consulta socio a socio: si el destino sirve o no depende de su
     * modalidad y su cohorte, y {@see WeeklyBasketGenerator::projectShareDelivery}
     * es la única fuente fiable para saberlo.
     *
     * @param Partner     $partner Socio de la lista de afectados.
     * @param Basket|null $to      Semana destino, o null.
     * @return string|null Motivo legible para el resumen, o null si es operable.
     */
    private function destinationBlockerFor(Partner $partner, ?Basket $to): ?string
    {
        if ($to === null) {
            return null;
        }

        $share = $this->em->getRepository(PartnerBasketShare::class)
            ->findActiveForPartner($partner, $to->getDate());
        if ($share === null || $this->generator->projectShareDelivery($to, $share) === null) {
            return 'no recoge en la semana de destino';
        }

        return null;
    }

    /**
     * Retira la línea de huevos de la entrega ya materializada, si la hay. El
     * intent por componente NO borra el WeeklyBasket (eso sólo pasa al saltar la
     * entrega entera), así que la piedra hay que tocarla aparte o el listado
     * seguiría enseñando las docenas.
     *
     * @param Partner         $partner Socio afectado.
     * @param Basket          $from    Semana de origen.
     * @param BasketComponent $eggs    Componente huevos.
     */
    private function removeMaterializedEggs(Partner $partner, Basket $from, BasketComponent $eggs): void
    {
        $wb = $this->weeklyBasketRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($wb instanceof WeeklyBasket) {
            $this->composer->removeComponent($wb, $eggs);
        }
    }

    /**
     * Invariantes de la operación entera (no del socio concreto): sobre qué
     * repartos se puede actuar.
     *
     * @param Basket      $from  Semana de origen.
     * @param Node[]      $nodes Puntos de recogida.
     * @param Basket|null $to    Semana destino, o null.
     * @throws \InvalidArgumentException Si la operación no es admisible.
     */
    private function assertOperable(Basket $from, array $nodes, ?Basket $to): void
    {
        if ($nodes === []) {
            throw new \InvalidArgumentException('Selecciona al menos un punto de recogida.');
        }

        $today = new \DateTimeImmutable('today');
        $fromDate = $from->getDate();
        if ($fromDate === null || \DateTimeImmutable::createFromInterface($fromDate)->setTime(0, 0) < $today) {
            throw new \InvalidArgumentException('Ese reparto ya pasó: no se puede modificar.');
        }

        if ($to === null) {
            return;
        }

        if ($to->getId() === $from->getId()) {
            throw new \InvalidArgumentException('El reparto de destino no puede ser el mismo que el de origen.');
        }

        $toDate = $to->getDate();
        if ($toDate === null || \DateTimeImmutable::createFromInterface($toDate)->setTime(0, 0) <= $today) {
            throw new \InvalidArgumentException('El reparto de destino tiene que ser posterior a hoy.');
        }
    }
}
