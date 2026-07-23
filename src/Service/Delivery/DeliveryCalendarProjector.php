<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Repository\DeliveryExceptionRepository;
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
final class DeliveryCalendarProjector implements PartnerMonthProjection
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
        private readonly WeeklyBasketComposer $composer,
        private readonly EggDeliveryResolver $eggResolver,
        private readonly DeliveryExceptionRepository $exceptionRepository,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
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
     * @return list<array{date: \DateTimeInterface, basket: Basket, source: 'materialized'|'projected', weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>, skipped: bool, listed: bool, available: list<BasketComponent>}>
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

        $node = $partner->getWeeklyBasketGroup()?->getNode();

        $slots = [];
        foreach ($baskets as $basket) {
            // ¿El listado de esta semana ya está generado (hay WB de algún socio)?
            // La pantalla NO deja editar contenido en semanas sin generar: materializar
            // un WB suelto dispararía la rama reuseExistingWeeklyBaskets del generador y
            // dejaría fuera al resto de socios. Mover sí es seguro (solo guarda el shift).
            $listed = $wbRepo->findOneBy(['basket' => $basket->getId()]) !== null;

            $outgoing = $shiftRepo->findOutgoing($partner, $basket);
            $incoming = $shiftRepo->findIncoming($partner, $basket);
            $materialized = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            $materializedActive = $materialized !== null
                && $materialized->getWeeklyBasketStatus()?->getId() !== WeeklyBasketSkipper::STATUS_SKIPPED;

            // CIERRE: una DeliveryException de cancelación (del nodo del socio o GLOBAL) deja
            // la semana SIN reparto para nadie. Manda sobre todo lo demás (skips, moves): el
            // día se pinta CERRADO (gris, no editable) y no se procesa nada más. El reparto y
            // el PDF ya lo respetan vía NodeDeliveryDate; aquí se cierra el hueco del
            // calendario del gestor, que en una semana YA generada leía el WeeklyBasket
            // materializado sin consultar la excepción y seguía mostrando las cestas.
            // (deliversInBasket no sirve para detectarlo: también es false en semanas alternas
            // de un nodo biweekly; hay que mirar la excepción de cancelación en concreto.)
            if ($node !== null && $this->exceptionRepository->findForBasketAndNode($basket, $node)?->isCancelled()) {
                $slots[] = [
                    'date' => $materialized?->getDeliveryDate() ?? $basket->getDate(),
                    'basket' => $basket,
                    'source' => 'projected',
                    'weeklyBasket' => $materialized ?? (new WeeklyBasket())->setBasket($basket)->setPartner($partner),
                    'items' => [],
                    'skipped' => false,
                    'closed' => true,
                    'listed' => $listed,
                    'available' => [],
                ];

                continue;
            }

            $isSkip = $outgoing !== null && $outgoing->isSkip();

            // PAPELERA: un "no recoge" (intent sin destino, to == null) aparca la cesta
            // NATIVA de esta semana. Se emite SIEMPRE su slot de papelera (skipped=true),
            // AUNQUE el día se haya reutilizado como destino de otra cesta movida — la cesta
            // aparcada vive en la papelera, NO en el día. Sin esto, al mover una cesta al día
            // de un "no recoge" el slot materializado tapaba al aparcado y la papelera lo
            // perdía de vista (acople día↔papelera). NO se hace continue: el mismo día puede
            // albergar además la cesta movida, cuyo slot de rejilla se emite más abajo. El
            // controller separa los skipped (papelera) de la rejilla.
            if ($isSkip) {
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
                    'listed' => $listed,
                    'available' => [],
                ];
            }

            // "Olvidar el origen": un día cuya entrega se MOVIÓ a otra fecha (outgoing con
            // destino) queda LIBRE, sin rastro — SALVO que esté OCUPADO por otra cosa: una
            // entrega entrante (otro día movido aquí) o un WB activo. Así un mismo día puede
            // ser a la vez origen de un cambio y destino de otro (12→19 y 26→12) y se pinta
            // ocupado por lo que recibió, no vacío. El "no recoge" SIN destino (to == null)
            // tampoco entra aquí: ocupa la papelera (entrega saltada, más abajo).
            if ($outgoing !== null && $outgoing->getToBasket() !== null
                && $incoming === null && !$materializedActive) {
                continue;
            }

            if ($materialized !== null) {
                $skipped = $materialized->getWeeklyBasketStatus()?->getId() === WeeklyBasketSkipper::STATUS_SKIPPED;
                $slots[] = [
                    'date' => $materialized->getDeliveryDate() ?? $basket->getDate(),
                    'basket' => $basket,
                    'source' => 'materialized',
                    'weeklyBasket' => $materialized,
                    'items' => $this->readItems($materialized),
                    'skipped' => $skipped,
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
                        'listed' => $listed,
                        'available' => array_map(static fn (array $line): BasketComponent => $line['component'], $items),
                    ];
                }
                continue;
            }

            // "No recoge" SIN destino: su papelera ya se emitió arriba y no llegó cesta
            // movida (ni materializada ni entrante), así que el día queda LIBRE en la
            // rejilla — no se pinta la cesta de patrón aquí.
            if ($isSkip) {
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
                'listed' => $listed,
                'available' => array_map(static fn (array $line): BasketComponent => $line['component'], $projection['items']),
            ];
        }

        // Reflejar los intents POR COMPONENTE (dry): en origen quita el componente de
        // la entrega de esa semana; en destino lo añade (creando un slot si esa semana
        // no tenía entrada proyectada). Es lo que muestra el plan de SANTOS antes de
        // generar. Va al final, cuando ya están todos los slots base (incluido el
        // huevo-extra). Los intents de entrega ENTERA se reflejan en el bucle de arriba.
        $slots = $this->applyComponentIntentsToSlots($partner, $baskets, $slots);

        // Overrides de CESTA EXTRA (dry): suman su delta a la entrega de esa semana, creando
        // un slot si su patrón no le daba cesta esa semana (p. ej. mensual en semana libre).
        $slots = $this->applyBasketExtrasToSlots($partner, $baskets, $slots);

        // Overrides de NODO (PartnerNodeOverride): el socio recoge esa semana en otro nodo.
        // Va el ÚLTIMO porque solo reubica (fecha física + etiqueta) los slots ya puestos,
        // incluidos los que crearon los pasos anteriores.
        return $this->applyNodeOverridesToSlots($partner, $baskets, $slots);
    }

    /**
     * Refleja en los slots los traslados puntuales de NODO ({@see PartnerNodeOverride}): la
     * semana se recoge en otro nodo. Recalcula la fecha física con el nodo destino (para que
     * la rejilla mueva el día — clave en semanas DIBUJADAS, donde la proyección usó el nodo de
     * casa; las materializadas ya traen la fecha buena del WeeklyBasket que tocó PickupRelocator)
     * y marca el slot con el nodo destino para que el panel del día muestre "Recoge en X". El
     * override existe solo si el nodo destino reparte esa semana (lo valida PickupRelocator), así
     * que no hay que reevaluar la cadencia aquí. Contraparte de calendario de
     * {@see WeeklyBasketGenerator::projectDeliveriesForNode} (camino de listado/PDF).
     *
     * @param Basket[]                                                   $baskets
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @return list<array{date: \DateTimeInterface, basket: Basket, ...}>
     */
    private function applyNodeOverridesToSlots(Partner $partner, array $baskets, array $slots): array
    {
        $overrideRepo = $this->em->getRepository(PartnerNodeOverride::class);

        // Un mismo Basket puede tener VARIOS slots (papelera + rejilla); todos se reubican.
        $idxsByBasket = [];
        foreach ($slots as $i => $slot) {
            $idxsByBasket[$slot['basket']->getId()][] = $i;
        }

        foreach ($baskets as $basket) {
            $override = $overrideRepo->findForPartnerAndBasket($partner, $basket);
            if ($override === null) {
                continue;
            }
            $destGroup = $override->getTargetGroup();
            $destNode = $destGroup?->getNode();
            if ($destNode === null) {
                continue;
            }

            $date = $this->nodeDeliveryDate->physicalDateFor($basket, $destNode) ?? $basket->getDate();
            foreach ($idxsByBasket[$basket->getId()] ?? [] as $idx) {
                $slots[$idx]['date'] = $date;
                $slots[$idx]['pickup_place'] = $destNode->getName();
                $slots[$idx]['relocated'] = true;
            }
        }

        return $slots;
    }

    /**
     * Refleja en los slots del calendario los overrides de CESTA EXTRA ({@see PartnerBasketExtra})
     * del socio: suma el delta de cada componente a la entrega de esa semana, creando un slot
     * si su patrón no le daba entrega (extra en una semana libre, p. ej. mensual). Es la
     * contraparte de calendario de {@see WeeklyBasketGenerator::applyBasketExtrasToProjection}.
     * Va al final, con los slots base ya puestos.
     *
     * @param Basket[]                                                   $baskets
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @return list<array{date: \DateTimeInterface, basket: Basket, ...}>
     */
    private function applyBasketExtrasToSlots(Partner $partner, array $baskets, array $slots): array
    {
        $extraRepo = $this->em->getRepository(PartnerBasketExtra::class);
        $shareRepo = $this->em->getRepository(\App\Entity\PartnerBasketShare::class);
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $node = $partner->getWeeklyBasketGroup()?->getNode();

        $idxByBasket = [];
        foreach ($slots as $i => $slot) {
            $idxByBasket[$slot['basket']->getId()] = $i;
        }

        foreach ($baskets as $basket) {
            $extras = $extraRepo->findForPartnerAndBasket($partner, $basket);
            if ($extras === []) {
                continue;
            }
            $idx = $idxByBasket[$basket->getId()] ?? null;

            // Si la entrega de esa semana ya está MATERIALIZADA, su WeeklyBasket (piedra) YA
            // lleva el extra cascadeado (ExtraBasketEditor::addToDelivery y el generador al
            // congelar lo suman a la piedra). Re-sumarlo aquí lo DUPLICABA en el calendario y el
            // panel (bug Antía: ½ docena de huevos extra se mostraba como 1). El extra solo se
            // DIBUJA sobre slots proyectados (dry, sin piedra) o cuando no había entrada esa
            // semana (idx null → se crea un slot dry más abajo).
            if ($idx !== null && ($slots[$idx]['source'] ?? null) === 'materialized') {
                continue;
            }

            if ($idx === null) {
                // Extra en una semana sin entrada (su patrón no le da cesta): crear el slot,
                // con la fecha física del nodo del socio y su modalidad (para el chip), 0 cestas
                // base. El extra se suma debajo.
                $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
                $date = ($node !== null ? $this->nodeDeliveryDate->physicalDateFor($basket, $node) : null) ?? $basket->getDate();
                $wb = (new WeeklyBasket())
                    ->setBasket($basket)
                    ->setPartner($partner)
                    ->setWeeklyBasketGroup($partner->getWeeklyBasketGroup())
                    ->setBasketShare($share?->getBasketShare())
                    ->setAmount(0)
                    ->setDeliveryDate($date);
                $slots[] = [
                    'date' => $date,
                    'basket' => $basket,
                    'source' => 'projected',
                    'weeklyBasket' => $wb,
                    'items' => [],
                    'skipped' => false,
                    'listed' => $wbRepo->findOneBy(['basket' => $basket->getId()]) !== null,
                    'available' => [],
                ];
                $idx = array_key_last($slots);
                $idxByBasket[$basket->getId()] = $idx;
            }

            foreach ($extras as $extra) {
                $component = $extra->getComponent();
                $delta = (float) $extra->getAmount();
                if ($component === null || $delta <= 0) {
                    continue;
                }
                $slots[$idx]['skipped'] = false;

                $merged = false;
                foreach ($slots[$idx]['items'] as &$line) {
                    if ($line['component']->getId() === $component->getId()) {
                        $line['amount'] = number_format((float) $line['amount'] + $delta, 2, '.', '');
                        $merged = true;
                        break;
                    }
                }
                unset($line);
                if (!$merged) {
                    $slots[$idx]['items'][] = ['component' => $component, 'amount' => number_format($delta, 2, '.', '')];
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

            // ORIGEN: quitar el componente de la entrega de esa semana. Sale de los
            // ítems siempre; de 'available' (el universo de interruptores) SOLO si se
            // MOVIÓ a otra fecha. En un "quitar" (drop, sin destino) se mantiene en
            // 'available' para que el interruptor siga visible (apagado) y se pueda
            // volver a activar — si no, desaparecería y no habría forma de re-añadirlo.
            foreach ($out as $intent) {
                if ($idx === null) {
                    continue;
                }
                $cid = $intent->getComponent()->getId();
                $slots[$idx]['items'] = array_values(array_filter(
                    $slots[$idx]['items'],
                    static fn (array $line): bool => $line['component']->getId() !== $cid,
                ));
                if (!$intent->isSkip()) {
                    $slots[$idx]['available'] = array_values(array_filter(
                        $slots[$idx]['available'],
                        static fn (BasketComponent $c): bool => $c->getId() !== $cid,
                    ));
                }
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
                        'listed' => $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket->getId()]) !== null,
                        'available' => [$component],
                    ];
                    $idx = array_key_last($slots);
                    $idxByBasket[$basket->getId()] = $idx;
                    continue;
                }

                // Mover un componente a un día "no recoge"/vaciado lo REVIVE para ese
                // componente: deja de estar saltado y pasa a entregar lo que llega.
                $slots[$idx]['skipped'] = false;

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
     * @return array{date: \DateTimeInterface, basket: Basket, source: 'projected', weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>, skipped: bool, listed: bool, available: list<BasketComponent>}|null
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
