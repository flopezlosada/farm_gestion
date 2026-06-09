<?php

namespace App\Service\Delivery;

use App\Custom\WeekOfMonth;
use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketItem;
use App\Entity\WeeklyBasketStatus;
use App\Entity\BasketShare;
use App\Repository\NodeRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Service\Partner\PartnerShareEventRecorder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encapsula la generación del listado semanal de cestas. Sustituye al
 * cuerpo legacy de PartnerBasketShareController::generateWeekly() sin
 * cambiar su comportamiento: se extrae para poder testearse y para que
 * el controller no cargue con la responsabilidad de orquestar BBDD.
 *
 * Aplica los cambios puntuales de viernes (PartnerDeliveryShift) cuando
 * se materializa el listado por primera vez: suprime a quien sale del
 * Basket y añade a quien entra (con su share activo).
 *
 * Respeta las excepciones de calendario (DeliveryException) de forma
 * indirecta: al resolver la fecha física de cada partner vía
 * NodeDeliveryDate, los ciclos cancelados devuelven null y el partner se
 * salta; los trasladados congelan la fecha movida en delivery_date.
 *
 * Lo que NO hace todavía: balancear A/B vía
 * PartnerBasketShare.delivery_group, emitir PartnerEvent.
 */
class WeeklyBasketGenerator
{
    /** ID de BasketShare semanal en el catálogo. */
    private const SHARE_WEEKLY = 1;
    /** ID de BasketShare quincenal. */
    private const SHARE_BIWEEKLY = 2;
    /** ID de BasketShare mensual. */
    private const SHARE_MONTHLY = 3;
    /** ID de BasketShare de media cesta (cesta compartida entre dos socios). */
    private const SHARE_HALF = 4;
    /** ID de BasketShare de solo huevos. */
    private const SHARE_ONLY_EGG = 5;
    /** ID de BasketShare quincenal compartida (media cesta, cadencia quincenal). */
    private const SHARE_BIWEEKLY_SHARED = 6;
    /** ID de BasketShare mensual compartida (media cesta, cadencia mensual). */
    private const SHARE_MONTHLY_SHARED = 7;

    /** ID de WeeklyBasketStatus "recogida" — estado inicial de cada cesta nueva. */
    private const STATUS_PICKED = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeliveryShiftApplier $shiftApplier,
        private readonly PartnerShareEventRecorder $shareEventRecorder,
        private readonly BiweeklyCohortResolver $cohortResolver,
        private readonly MonthlyOperativeOrderResolver $monthlyResolver,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly NodeRepository $nodeRepository,
        private readonly EggDeliveryResolver $eggResolver,
        private readonly WeeklyBasketComposer $composer,
    ) {
    }

    /**
     * Devuelve los IDs de nodos biweekly que reparten en este Basket
     * (su alternancia anchor coincide).
     *
     * @param Basket $basket
     * @return int[]
     */
    private function activeBiweeklyNodeIds(Basket $basket): array
    {
        return array_map(
            static fn (Node $node) => $node->getId(),
            $this->activeBiweeklyNodes($basket),
        );
    }

    /**
     * Orden operativo del Basket entre los viernes del mes del nodo weekly
     * (Torremocha). Null si Torremocha no entrega en este Basket (excepción
     * global o de nodo cancela). En tal caso la rama weekly+sin-nodo de la
     * query mensual node-aware no debe traer partners — el repo lo respeta
     * ignorando `$weeklyMonthlyOrder` cuando es null.
     *
     * Asume un único nodo weekly en el sistema (hoy Torremocha). Si en el
     * futuro hubiera varios nodos weekly compartiendo día y excepciones,
     * el orden es el mismo y este método sigue valiendo; si comparten
     * cadencia pero NO día, habría que rediseñar.
     *
     * Sub-fase 8.8e (2026-05-28): sustituye al hardcode NON_OPERATIVE_FRIDAYS.
     *
     * @param Basket $basket
     * @return int|null 1-based, o null si el nodo weekly no entrega esta semana.
     */
    private function weeklyMonthlyOrderFor(Basket $basket): ?int
    {
        $weeklyNode = $this->nodeRepository->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($weeklyNode === null) {
            return null;
        }

        return $this->monthlyResolver->operativeOrderForNode($basket, $weeklyNode);
    }

    /**
     * Mapa nodeId → orden operativo del Basket dentro del calendario del
     * nodo biweekly, para los nodos biweekly que reparten esta semana.
     * Usado por la query mensual node-aware para emparejar partners cuyo
     * `day_month_order` se cuenta sobre las entregas reales del nodo,
     * no sobre los viernes-ciclo.
     *
     * Sub-fase 8.8b3 (2026-05-28).
     *
     * @param Basket $basket
     * @return array<int,int> nodeId → 1-based operative order del nodo.
     */
    private function monthlyOrderByBiweeklyNode(Basket $basket): array
    {
        $map = [];
        foreach ($this->activeBiweeklyNodes($basket) as $node) {
            $order = $this->monthlyResolver->operativeOrderForNode($basket, $node);
            if ($order !== null) {
                $map[$node->getId()] = $order;
            }
        }
        return $map;
    }

    /**
     * Nodos biweekly que reparten en este Basket. Helper compartido por
     * activeBiweeklyNodeIds y monthlyOrderByBiweeklyNode.
     *
     * @param Basket $basket
     * @return Node[]
     */
    private function activeBiweeklyNodes(Basket $basket): array
    {
        $biweeklyNodes = $this->nodeRepository->findByCadence(Node::CADENCE_BIWEEKLY);
        return array_values(array_filter(
            $biweeklyNodes,
            fn (Node $node) => $this->nodeDeliveryDate->deliversInBasket($basket, $node),
        ));
    }

    /**
     * Calcula la fecha física de reparto para un partner en un Basket.
     * Si el partner pertenece a un nodo configurado, delega en
     * NodeDeliveryDate (que aplica cadencia y excepciones de calendario).
     * Si no (datos legacy sin node_id), asume Torremocha implícito y
     * devuelve la fecha del Basket.
     *
     * Límite conocido: el fallback sin nodo NO consulta excepciones, así
     * que un partner en un WBG sin nodo asignado ignoraría un cierre. En
     * la práctica todos los WBG operativos tienen nodo; los huérfanos son
     * deuda de datos a saldar asignándoles nodo (típicamente Torremocha).
     *
     * @param Basket $basket
     * @param PartnerBasketShare $share
     * @return \DateTimeInterface|null Null si el nodo no reparte en este Basket (biweekly fuera de fase o excepción que cancela).
     */
    private function resolvePhysicalDeliveryDate(Basket $basket, PartnerBasketShare $share): ?\DateTimeInterface
    {
        $node = $share->getPartner()->getWeeklyBasketGroup()?->getNode();
        if ($node === null) {
            return $basket->getDate();
        }

        return $this->nodeDeliveryDate->physicalDateFor($basket, $node);
    }

    /**
     * Construye o recupera el reparto correspondiente al Basket dado.
     * Si los WeeklyBasket ya existen para este Basket, los reutiliza
     * (es lo que permite que cambios puntuales de turno se respeten).
     * Si no existen, los crea a partir de los PartnerBasketShare activos.
     *
     * Como efecto secundario adicional, antes de nada desactiva las
     * PartnerBasketShare cuya end_date ya quedó atrás (mantiene el
     * histórico limpio).
     *
     * @param Basket $basket Cesta del viernes a procesar.
     * @return WeeklyDeliveryReport DTO con todo lo que necesita la vista o el PDF.
     */
    public function generateForBasket(Basket $basket): WeeklyDeliveryReport
    {
        $this->finalizeExpiredShares();

        $controlExisting = $this->em->getRepository(WeeklyBasket::class)
            ->findBy(['basket' => $basket->getId()]);

        $dayOrder = WeekOfMonth::dayOfWeekInMonth($basket->getDate());

        if ($controlExisting) {
            [$weekly, $half, $biweekly, $monthly, $onlyEgg] =
                $this->reuseExistingWeeklyBaskets($basket);
        } else {
            [$weekly, $half, $biweekly, $monthly, $onlyEgg] =
                $this->createWeeklyBasketsFromShares($basket, $dayOrder);
        }

        $this->em->flush();

        $weeklyBasketGroups = $this->em->getRepository(WeeklyBasketGroup::class)->findAll();

        return new WeeklyDeliveryReport(
            basket: $basket,
            weekly_partners: $weekly,
            biweekly_partners: $biweekly,
            monthly_partners: $monthly,
            old_half_basket_partners: $half,
            only_egg_partners: $onlyEgg,
            weekly_basket_groups: $weeklyBasketGroups,
            basket_weekly_partners_amount: $this->sumAmount($weekly),
            basket_biweekly_partners_amount: $this->sumAmount($biweekly),
            basket_monthly_partners_amount: $this->sumAmount($monthly),
            basket_old_half_basket_partners_amount: $this->sumAmount($half),
            basket_only_egg_partners_amount: $this->sumAmount($onlyEgg),
        );
    }

    /**
     * Marca como inactivas las suscripciones (PartnerBasketShare) cuya end_date
     * sea anterior al viernes de esta semana. Para cestas compartidas (share id 4)
     * además rompe el vínculo del socio par para que no quede colgando.
     */
    private function finalizeExpiredShares(): void
    {
        $shares = $this->em->getRepository(PartnerBasketShare::class)->findFinalized();

        foreach ($shares as $share) {
            $share->setIsActive(0);
            if ($share->getBasketShare()->getId() === self::SHARE_HALF) {
                // Comportamiento legacy preservado: las cestas compartidas dejan de
                // tener pareja cuando una de las dos se finaliza → se rompe el vínculo
                // share_partner en AMBOS sentidos. (Era getBasketShare(), método que NO
                // existe en Partner: la rama nunca se había ejercitado y tumbaba toda la
                // generación del listado al finalizar una compartida.)
                $pairPartner = $share->getPartner()->getSharePartner();
                $share->getPartner()->setSharePartner(null);
                $this->em->persist($share->getPartner());
                if ($pairPartner !== null) {
                    $pairPartner->setSharePartner(null);
                    $this->em->persist($pairPartner);
                }
            }
            $this->em->persist($share);

            // Cierre automático por end_date pasada: el evento se atribuye al
            // sistema (no hay gestor humano detrás) y la fecha real es la
            // end_date del share, no "ahora".
            $this->shareEventRecorder->recordEnd(
                $share,
                $share->getEndDate(),
                PartnerEvent::ACTOR_SYSTEM,
            );
        }
    }

    /**
     * El listado ya existe en weekly_basket: se recuperan agrupados por share.
     * Esta rama respeta los cambios puntuales: si alguien fue retirado del
     * listado (borrado el WeeklyBasket), no reaparece.
     *
     * Nota sobre data legacy (snapshot 2026-05): hay ~8% de WeeklyBasket
     * cuyos Partner ya no tienen ningún PartnerBasketShare activo (socios
     * dados de baja en el pasado cuyos WeeklyBasket quedaron grabados).
     * No es un bug aquí: PartnerLifecycleSubscriber::leave borra los
     * WeeklyBasket futuros, pero los históricos del snapshot no pasaron
     * por ese listener. El template _table_list_basket es defensivo
     * ante este caso y muestra "—" para esos partners.
     *
     * @return array{0:array,1:array,2:array,3:array,4:array} weekly, half, biweekly, monthly, onlyEgg
     */
    private function reuseExistingWeeklyBaskets(Basket $basket): array
    {
        $weeklyRepo = $this->em->getRepository(WeeklyBasket::class);

        $weekly = $weeklyRepo->findBy(['basket_share' => self::SHARE_WEEKLY, 'basket' => $basket]);
        $half = $weeklyRepo->findBy(['basket_share' => self::SHARE_HALF, 'basket' => $basket]);
        // Las compartidas quincenal/mensual (6/7) viajan con sus no-compartidas (2/3):
        // moveSharedToHalf las reubica luego al bloque de compartidas por share_partner_id.
        $biweekly = $weeklyRepo->findBy(['basket_share' => [self::SHARE_BIWEEKLY, self::SHARE_BIWEEKLY_SHARED], 'basket' => $basket]);
        $monthly = $weeklyRepo->findBy(['basket_share' => [self::SHARE_MONTHLY, self::SHARE_MONTHLY_SHARED], 'basket' => $basket]);
        $onlyEgg = $weeklyRepo->findBy(['basket_share' => self::SHARE_ONLY_EGG, 'basket' => $basket]);

        // La sección "compartidas" del PDF agrupa toda media cesta — semanal,
        // quincenal o lo que sea — bajo el criterio share_partner_id != null,
        // no por basket_share_id. Se reubican aquí los WBs cuyo Partner tiene
        // pareja de cesta, vengan de la modalidad que vengan.
        $this->moveSharedToHalf($weekly, $half);
        $this->moveSharedToHalf($biweekly, $half);
        $this->moveSharedToHalf($monthly, $half);
        $this->moveSharedToHalf($onlyEgg, $half);

        $this->stampCurrentBasketOnPartners(
            array_merge($weekly, $biweekly, $monthly, $onlyEgg),
            $basket
        );
        $this->stampHalfBasketPairs($half, $basket);

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
    }

    /**
     * Saca de la colección de modalidad a los items cuyo Partner tiene
     * share_partner_id != null (= comparte cesta con otra familia) y los
     * mueve a la colección $half (sección "compartidas" del listado).
     *
     * Sirve para WeeklyBasket y PartnerBasketShare indistintamente: ambos
     * exponen getPartner().
     *
     * @param array $source Modificada por referencia: queda sin los compartidos.
     * @param array $half   Modificada por referencia: recibe los compartidos.
     */
    private function moveSharedToHalf(array &$source, array &$half): void
    {
        $remaining = [];
        foreach ($source as $item) {
            if ($item->getPartner()->getSharePartner() !== null) {
                $half[] = $item;
            } else {
                $remaining[] = $item;
            }
        }
        $source = $remaining;
    }

    /**
     * Aún no existen WeeklyBasket para esta cesta: se calculan los candidatos a
     * partir de los PartnerBasketShare activos según modalidad y se persisten.
     *
     * Antes de persistir aplica los cambios puntuales de viernes registrados
     * sobre este Basket: excluye a los partners que tienen un shift saliendo
     * y, al final, materializa una WB extra para los partners que tienen un
     * shift entrando (cuyo share normal no los habría incluido).
     *
     * @return array{0:array,1:array,2:array,3:array,4:array} weekly, half, biweekly, monthly, onlyEgg
     */
    private function createWeeklyBasketsFromShares(Basket $basket, int $dayOrder): array
    {
        [$weekly, $half, $biweekly, $monthly, $onlyEgg] = $this->gatherCandidateShares($basket);

        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);
        $outgoing = $shiftRepo->findAllOutgoingFromBasket($basket);
        // Solo los intents de ENTREGA ENTERA (sin componente) sacan al socio de los
        // candidatos de la semana — tanto mover toda la cesta como "no recoge" (to
        // null). Un intent POR COMPONENTE (mover solo la verdura) NO excluye al socio:
        // ajusta su composición en el pase final (applyComponentIntents).
        $wholeOutgoingPartnerIds = $this->wholeOutgoingPartnerIds($outgoing);

        // Node-overrides de esta semana: el socio recoge en OTRO nodo. Se EXCLUYE de los
        // candidatos de su nodo de casa (igual que un saliente de entrega entera) y más
        // abajo se materializa en su grupo destino. Ver docs/redesign/modelo-overrides-reparto.md.
        $nodeOverrides = $this->em->getRepository(PartnerNodeOverride::class)->findAllForBasket($basket);
        $nodeOverridePartnerIds = array_map(
            static fn (PartnerNodeOverride $o): int => $o->getPartner()->getId(),
            $nodeOverrides,
        );

        $excludedPartnerIds = array_merge($wholeOutgoingPartnerIds, $nodeOverridePartnerIds);
        if (!empty($excludedPartnerIds)) {
            $excludeShares = static function (array $shares) use ($excludedPartnerIds): array {
                return array_values(array_filter(
                    $shares,
                    static fn ($share) => !in_array($share->getPartner()->getId(), $excludedPartnerIds, true),
                ));
            };
            $weekly = $excludeShares($weekly);
            $half = $excludeShares($half);
            $biweekly = $excludeShares($biweekly);
            $monthly = $excludeShares($monthly);
            $onlyEgg = $excludeShares($onlyEgg);
        }

        $all = array_merge($weekly, $half, $biweekly, $monthly, $onlyEgg);
        $status = $this->em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED);

        foreach ($all as $share) {
            $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
            if ($physicalDate === null) {
                // El nodo del partner no reparte en este Basket
                // (caso típico: nodo biweekly fuera de fase con su ancla).
                continue;
            }

            $wb = $this->newWeeklyBasketForShare($basket, $share, $physicalDate, $status);
            $this->em->persist($wb);
            $this->em->flush();

            // Etapa 1 calendario: materializar la composición (líneas de componente).
            $this->composer->compose($wb, $share, $basket);

            $stamped = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket->getId(), 'partner' => $share->getPartner()->getId()]);
            $share->getPartner()->setCurrentBasket($stamped);
        }

        $incoming = $shiftRepo->findAllIncomingToBasket($basket);
        foreach ($incoming as $shift) {
            // Los intents POR COMPONENTE entrantes se aplican en el pase final
            // (añaden un componente, no materializan una entrega entera).
            if (!$shift->isWholeDelivery()) {
                continue;
            }
            $partner = $shift->getPartner();
            $existing = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($existing !== null) {
                continue;
            }
            $destWb = $this->shiftApplier->createWeeklyBasketForShiftDestination($partner, $basket);
            $this->em->flush();

            // Componer la entrega destino del cambio puntual. Si el ORIGEN está
            // materializado (conserva lo quitado a mano), se COPIAN sus líneas: la
            // composición real viaja con la cesta. Si no, se deriva del patrón con los
            // huevos del origen (el huevo viaja igual, caso Franco). Sin esto, mover en
            // un mes aún sin generar perdería la personalización al materializar aquí.
            $sourceWb = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $shift->getFromBasket()->getId(), 'partner' => $partner->getId()]);
            if ($sourceWb !== null) {
                $this->composer->stamp($destWb, $this->composer->copyLines($sourceWb));
            } else {
                $destShare = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner);
                if ($destShare !== null) {
                    $destWb->setPartnerBasketShare($destShare);
                    $this->composer->compose($destWb, $destShare, $basket, eggReferenceBasket: $shift->getFromBasket());
                }
            }
        }

        // Node-overrides: materializar la entrega del socio en su GRUPO DESTINO esta semana
        // (no en su nodo de casa, de donde se le excluyó arriba), si su patrón le da cesta.
        // Compuesta desde su patrón, con la fecha física del nodo destino. El listado del
        // destino la verá como "trasladado" (wb.grupo.node != nodo de casa del socio).
        foreach ($nodeOverrides as $override) {
            $partner = $override->getPartner();
            if ($this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]) !== null) {
                continue; // ya materializado (p. ej. un shift entrante)
            }
            $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
            if ($share === null || $this->resolvePhysicalDeliveryDate($basket, $share) === null) {
                continue; // su patrón no le da cesta esta semana: nada que trasladar
            }
            $targetGroup = $override->getTargetGroup();
            $destNode = $targetGroup->getNode();
            $physical = $destNode !== null ? $this->nodeDeliveryDate->physicalDateFor($basket, $destNode) : null;
            $wb = $this->newWeeklyBasketForShare($basket, $share, $physical ?? $basket->getDate(), $status);
            $wb->setWeeklyBasketGroup($targetGroup);
            $this->em->persist($wb);
            $this->em->flush();
            $this->composer->compose($wb, $share, $basket);
        }

        $extraEggOnly = $this->materializeExtraEggDeliveries($basket, $status);
        $onlyEgg = array_merge($onlyEgg, $extraEggOnly);

        $this->applyComponentIntents($basket, $outgoing, $incoming);

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
    }

    /**
     * Aplica los intents POR COMPONENTE que tocan esta semana, DESPUÉS de toda la
     * materialización normal (candidatos + entrantes enteros + huevo extra) para que
     * el WeeklyBasket ya exista cuando haya que ajustarlo. Es lo que permite a SANTOS
     * (mensual verdura + semanal huevo) mover SOLO la verdura sin tocar el huevo:
     *  - origen (from): quita ese componente de la entrega del socio esa semana; los
     *    demás componentes siguen su propio calendario.
     *  - destino (to): añade ese componente, creando la entrega si esa semana no tenía
     *    ninguna (p. ej. la verdura aterriza en una semana de solo huevo).
     *
     * @param Basket                 $basket
     * @param PartnerDeliveryShift[] $outgoing Intents que SALEN de $basket.
     * @param PartnerDeliveryShift[] $incoming Intents que ENTRAN a $basket.
     */
    private function applyComponentIntents(Basket $basket, array $outgoing, array $incoming): void
    {
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);

        foreach ($outgoing as $shift) {
            if ($shift->isWholeDelivery()) {
                continue;
            }
            $wb = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $shift->getPartner()->getId()]);
            if ($wb !== null) {
                $this->composer->removeComponent($wb, $shift->getComponent());
            }
        }

        foreach ($incoming as $shift) {
            if ($shift->isWholeDelivery()) {
                continue;
            }
            $partner = $shift->getPartner();
            $component = $shift->getComponent();
            $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
            if ($share === null) {
                continue;
            }

            $wb = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($wb === null) {
                // El componente aterriza en una semana sin ninguna entrega del socio:
                // se crea la entrega configurada con su modalidad/fecha de nodo.
                $wb = $this->shiftApplier->createWeeklyBasketForShiftDestination($partner, $basket);
                $this->em->flush();
            } elseif ($component->getId() === BasketComponent::ID_VEGETABLES
                && ($wb->getBasketShare()?->getDeliveredBasketWeight() ?? 0.0) <= 0.0) {
                // La verdura cae en una entrega "solo-huevo" (peso 0): realinear el WB
                // a la modalidad real del socio para que su agrupación/cantidad sean
                // coherentes (la cantidad de la línea ya sale del share, pero el WB
                // seguiría marcado como solo-huevo).
                $wb->setBasketShare($share->getBasketShare());
                $wb->setAmount($share->getAmount());
                $wb->setPartnerBasketShare($share);
            }

            $this->composer->addComponent($wb, $share, $component);
        }

        $this->em->flush();
    }

    /**
     * Reúne los PartnerBasketShare candidatos a repartir en un Basket,
     * agrupados por modalidad y con las comparticiones ya reubicadas a la
     * sección "compartidas". SOLO LECTURA: ejecuta finders y resolvers,
     * pero no persiste nada. Es la mitad de proyección que comparten el
     * camino de generación (que luego materializa WeeklyBasket) y la
     * estimación de la ficha de nodo (que solo cuenta).
     *
     * No aplica los cambios puntuales de viernes (PartnerDeliveryShift):
     * eso vive en el camino de escritura, porque altera quién entra/sale.
     *
     * @param Basket $basket
     * @return array{0:PartnerBasketShare[],1:PartnerBasketShare[],2:PartnerBasketShare[],3:PartnerBasketShare[],4:PartnerBasketShare[]} weekly, half, biweekly, monthly, onlyEgg
     */
    private function gatherCandidateShares(Basket $basket): array
    {
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);

        $cohort = $this->cohortResolver->cohortForBasket($basket);
        $weeklyMonthlyOrder = $this->weeklyMonthlyOrderFor($basket);
        $activeBiweeklyNodeIds = $this->activeBiweeklyNodeIds($basket);
        $biweeklyNodeMonthlyOrders = $this->monthlyOrderByBiweeklyNode($basket);

        $weekly = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_WEEKLY, 1, $basket);
        $half = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_HALF, 1, $basket);
        $biweekly = $shareRepo->findBasketPartnersBiweeklyNodeAware($basket, self::SHARE_BIWEEKLY, 1, $cohort, $activeBiweeklyNodeIds);
        $monthly = $shareRepo->findBasketPartnersMonthlyNodeAware($basket, self::SHARE_MONTHLY, $weeklyMonthlyOrder, $biweeklyNodeMonthlyOrders);

        // Quincenal/mensual COMPARTIDA (bs 6/7): misma cadencia que su no-compartida
        // (cohorte quincenal / orden operativo mensual), pero media cesta. Se buscan
        // con su propio basket_share para que el WB nazca como 6/7; el composer aplica
        // el peso ½ (getDeliveredBasketWeight) y moveSharedToHalf las reubica al bloque
        // de compartidas (tienen share_partner_id). Sin esto su cesta no se materializa.
        $biweekly = array_merge($biweekly, $shareRepo->findBasketPartnersBiweeklyNodeAware($basket, self::SHARE_BIWEEKLY_SHARED, 1, $cohort, $activeBiweeklyNodeIds));
        $monthly = array_merge($monthly, $shareRepo->findBasketPartnersMonthlyNodeAware($basket, self::SHARE_MONTHLY_SHARED, $weeklyMonthlyOrder, $biweeklyNodeMonthlyOrders));

        $onlyEggWeekly = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_ONLY_EGG, 1, $basket, true);
        $onlyEggBiweekly = $shareRepo->findBasketPartnersBiweeklyNodeAware($basket, self::SHARE_ONLY_EGG, 1, $cohort, $activeBiweeklyNodeIds, true);
        $onlyEggMonthly = $shareRepo->findBasketPartnersMonthlyNodeAware($basket, self::SHARE_ONLY_EGG, $weeklyMonthlyOrder, $biweeklyNodeMonthlyOrders, true);
        $onlyEgg = array_merge($onlyEggWeekly, $onlyEggBiweekly, $onlyEggMonthly);

        // Misma lógica que en reuseExisting: cualquier compartición (share_partner_id
        // != null) se reubica a la sección "compartidas", independientemente de la
        // frecuencia. Una QC/QCH del PDF aparece en COMPARTIDAS porque comparte, no
        // porque sea semanal.
        $this->moveSharedToHalf($weekly, $half);
        $this->moveSharedToHalf($biweekly, $half);
        $this->moveSharedToHalf($monthly, $half);
        $this->moveSharedToHalf($onlyEgg, $half);

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
    }

    /**
     * Proyección de solo lectura: qué PartnerBasketShare repartirían en un
     * Basket según modalidad, cadencia y cohorte, SIN materializar ni
     * persistir nada. Pensado para estimar "cuánto se va a repartir" en una
     * semana futura, o "cuánto se estimó" en una pasada aún sin generar, sin
     * el efecto secundario de generateForBasket (que escribe).
     *
     * Devuelve la lista plana de shares candidatas (todas las modalidades,
     * incluidas las compartidas y las de solo-huevo). El llamante decide
     * cómo filtrar (p. ej. por nodo) y ponderar (cestas por modalidad).
     *
     * No incluye los cambios puntuales de viernes: es una estimación, no el
     * listado definitivo.
     *
     * @param Basket $basket
     * @return PartnerBasketShare[]
     */
    public function projectForBasket(Basket $basket): array
    {
        [$weekly, $half, $biweekly, $monthly, $onlyEgg] = $this->gatherCandidateShares($basket);

        return array_merge($weekly, $half, $biweekly, $monthly, $onlyEgg);
    }

    /**
     * Construye el WeeklyBasket de un share para un Basket. NO persiste ni hace
     * flush: solo arma la entidad. Lo comparten el camino de escritura
     * (createWeeklyBasketsFromShares, que luego persiste + compone) y la
     * proyección de solo lectura del calendario (projectShareDelivery, modo dry).
     *
     * @param Basket                  $basket
     * @param PartnerBasketShare      $share
     * @param \DateTimeInterface      $physicalDate Fecha física de entrega ya resuelta por el nodo.
     * @param WeeklyBasketStatus|null $status       Estado inicial; null en modo dry (entrega transitoria).
     * @return WeeklyBasket Entidad transitoria, sin persistir.
     */
    private function newWeeklyBasketForShare(
        Basket $basket,
        PartnerBasketShare $share,
        \DateTimeInterface $physicalDate,
        ?WeeklyBasketStatus $status,
    ): WeeklyBasket {
        return (new WeeklyBasket())
            ->setBasket($basket)
            ->setPartner($share->getPartner())
            ->setWeeklyBasketStatus($status)
            ->setBasketShare($share->getBasketShare())
            ->setWeeklyBasketGroup($share->getPartner()->getWeeklyBasketGroup())
            ->setAmount($share->getAmount())
            ->setDeliveryDate($physicalDate)
            ->setPartnerBasketShare($share);
    }

    /**
     * Proyección de solo lectura de la entrega que un share materializaría en un
     * Basket (modo dry de B', calendario de recogida): arma un WeeklyBasket
     * TRANSITORIO (no persiste) y calcula sus líneas de componente con el mismo
     * computeItems que usa el camino de escritura. Una sola fuente de lógica para
     * "qué hay en una entrega", se muestre o se guarde.
     *
     * Devuelve null si el nodo del socio no reparte en este Basket (misma regla
     * que el camino de escritura). NO aplica cambios puntuales (shifts) ni
     * huevos-extra de socios con huevo más frecuente que la cesta (SANTOS): eso
     * lo orquesta el proyector de mes que se monta encima.
     *
     * @param Basket             $basket
     * @param PartnerBasketShare $share
     * @param Basket|null        $eggReferenceBasket Basket contra el que decidir la
     *        cadencia de huevos (ver WeeklyBasketComposer::compose). Por defecto el
     *        propio $basket; se pasa el ORIGEN para proyectar una cesta movida (los
     *        huevos viajan con ella).
     * @return null|array{weeklyBasket: WeeklyBasket, items: array<int, array{component: \App\Entity\BasketComponent, amount: string}>, deliveryDate: \DateTimeInterface}
     */
    public function projectShareDelivery(Basket $basket, PartnerBasketShare $share, ?Basket $eggReferenceBasket = null): ?array
    {
        $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
        if ($physicalDate === null) {
            return null;
        }

        $wb = $this->newWeeklyBasketForShare($basket, $share, $physicalDate, null);

        return [
            'weeklyBasket' => $wb,
            'items' => $this->composer->computeItems($wb, $share, $basket, $eggReferenceBasket),
            'deliveryDate' => $physicalDate,
        ];
    }

    /**
     * Proyección de SOLO LECTURA del listado de un NODO en un día: las líneas de
     * entrega ({@see DeliveryLine}) dibujadas al vuelo desde las reglas (patrón,
     * cohorte, cadencia, festivos) MÁS los cambios puntuales de ENTREGA ENTERA
     * (mover de día y "no recoge"), SIN materializar ni persistir nada. Es la
     * contraparte de DIBUJO del camino de PIEDRA {@see NodeDeliverySheet::build}:
     * ambas producen DeliveryLine[] que el mismo shaper convierte en listado, así
     * que una semana futura aún sin generar refleja al instante un festivo o un
     * cambio metido tarde (cierra el agujero de la materialización temprana).
     *
     * Mapeo fino sobre {@see projectDeliveriesForNode}, que es la orquestación
     * común: el listado en papel consume estas {@see DeliveryLine}; la pantalla v2
     * consume los WeeklyBasket transitorios directamente.
     *
     * @param Node   $node
     * @param Basket $basket
     * @return DeliveryLine[]
     */
    public function projectLinesForNode(Node $node, Basket $basket): array
    {
        return array_map(
            fn (array $delivery): DeliveryLine => $this->lineFromProjection(
                $delivery['weeklyBasket'],
                $delivery['items'],
            ),
            $this->projectDeliveriesForNode($node, $basket),
        );
    }

    /**
     * Ids de socio cuyos intents de ENTREGA ENTERA (mover toda la cesta o "no
     * recoge", `component` null) SALEN de un Basket: se excluyen del reparto de esa
     * semana. Los intents POR COMPONENTE (mover solo la verdura) NO sacan al socio,
     * solo ajustan su composición. Criterio compartido por el camino de ESCRITURA
     * ({@see createWeeklyBasketsFromShares}) y el de PROYECCIÓN
     * ({@see projectDeliveriesForNode}) para no duplicarlo (deuda DRY proyeccion-
     * orquestacion-dry-debt, pieza 1).
     *
     * @param PartnerDeliveryShift[] $outgoing Intents que salen del Basket.
     * @return int[]
     */
    private function wholeOutgoingPartnerIds(array $outgoing): array
    {
        return array_map(
            static fn (PartnerDeliveryShift $s) => $s->getPartner()->getId(),
            array_filter($outgoing, static fn (PartnerDeliveryShift $s) => $s->isWholeDelivery()),
        );
    }

    /**
     * Orquestación de la PROYECCIÓN de un nodo+día: produce los WeeklyBasket
     * TRANSITORIOS (no persistidos) con sus líneas de componente ya calculadas,
     * aplicando patrón base + cohorte/cadencia/festivos, los cambios puntuales de
     * ENTREGA ENTERA (mover de día y "no recoge", in/out) y el huevo extra tipo
     * SANTOS. Es el alimentador común de dos consumidores: {@see projectLinesForNode}
     * (listado en papel vía DeliveryLine) y la pantalla v2
     * ({@see \App\Controller\DeliveryController::byNode}), que recibe los WB
     * transitorios y reusa su mismo shaping/plantilla SIN congelar la semana.
     *
     * Deuda DRY (memoria proyeccion-orquestacion-dry-debt): duplica la
     * orquestación de {@see createWeeklyBasketsFromShares}. Ya cubre los intents POR
     * COMPONENTE (SANTOS mueve solo la verdura, vía
     * {@see applyComponentIntentsToProjection}); queda pendiente la fidelidad fina de
     * composición copiada del origen en entrantes con cesta editada a mano (que el
     * materializado sí copia con copyLines). El check de equivalencia
     * proyección-vs-materializado de {@see \App\Command\VerifyDeliveryBatteryCommand}
     * es la red que delata las divergencias, antes de converger a orquestación
     * compartida (opción A).
     *
     * @param Node   $node
     * @param Basket $basket
     * @return list<array{weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>}>
     */
    public function projectDeliveriesForNode(Node $node, Basket $basket): array
    {
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);
        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);

        // Cambios de ENTREGA ENTERA que SALEN de esta semana (mover a otro día o
        // "no recoge"): sacan al socio del dibujo, igual que en el camino de escritura.
        $outgoing = $shiftRepo->findAllOutgoingFromBasket($basket);
        $wholeOutgoingPartnerIds = $this->wholeOutgoingPartnerIds($outgoing);

        // Cambios de NODO (PartnerNodeOverride) de esta semana: el override mueve dónde
        // recoge el socio, no si recoge. relocatedOutPartnerIds = socios de ESTE nodo que
        // esa semana recogen en OTRO nodo (salen del dibujo de su nodo de casa). Más abajo
        // se añaden los que ENTRAN a este nodo. Ver docs/redesign/modelo-overrides-reparto.md.
        $nodeOverrides = $this->em->getRepository(PartnerNodeOverride::class)->findAllForBasket($basket);
        $relocatedOutPartnerIds = [];
        foreach ($nodeOverrides as $override) {
            if ($override->getTargetNode()?->getId() !== $node->getId()) {
                $relocatedOutPartnerIds[$override->getPartner()->getId()] = true;
            }
        }

        $deliveries = [];
        $seenPartnerIds = [];
        foreach ($this->projectForBasket($basket) as $share) {
            $partner = $share->getPartner();
            if (!$this->partnerBelongsToNode($partner, $node)) {
                continue;
            }
            if (in_array($partner->getId(), $wholeOutgoingPartnerIds, true)) {
                continue;
            }
            if (isset($relocatedOutPartnerIds[$partner->getId()])) {
                continue; // esta semana recoge en otro nodo (override de nodo)
            }
            $projection = $this->projectShareDelivery($basket, $share);
            if ($projection === null) {
                continue; // el nodo del socio no reparte esta semana (cadencia/festivo)
            }
            $deliveries[] = ['weeklyBasket' => $projection['weeklyBasket'], 'items' => $projection['items']];
            $seenPartnerIds[$partner->getId()] = true;
        }

        // Cambios de ENTREGA ENTERA que ENTRAN a esta semana: el socio recoge aquí
        // por un cambio, aunque su patrón no lo trajera (incluso en un día donde su
        // nodo no repartiría: el shift fuerza la fecha física, como en el applier).
        $incoming = $shiftRepo->findAllIncomingToBasket($basket);
        foreach ($incoming as $shift) {
            if (!$shift->isWholeDelivery()) {
                continue;
            }
            $partner = $shift->getPartner();
            if (!$this->partnerBelongsToNode($partner, $node) || isset($seenPartnerIds[$partner->getId()])) {
                continue;
            }
            $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
            if ($share === null) {
                continue;
            }
            $node_ = $partner->getWeeklyBasketGroup()?->getNode();
            $date = $node_ !== null
                ? ($this->nodeDeliveryDate->physicalDateFor($basket, $node_) ?? $basket->getDate())
                : $basket->getDate();

            $wb = $this->newWeeklyBasketForShare($basket, $share, $date, null);
            // La composición se deriva del patrón con los huevos del ORIGEN como
            // referencia (mismo criterio que stampDestination del applier).
            $items = $this->composer->computeItems($wb, $share, $basket, $shift->getFromBasket());
            $deliveries[] = ['weeklyBasket' => $wb, 'items' => $items];
            $seenPartnerIds[$partner->getId()] = true;
        }

        // Cambios de NODO que ENTRAN a este nodo (PartnerNodeOverride → este nodo): el
        // socio recoge aquí esta semana en vez de en su nodo de casa, SI su patrón le da
        // cesta (projectShareDelivery null = no le toca → nada que trasladar). Se compone
        // desde su patrón y se reapunta al grupo destino con la fecha física del destino.
        // El listado lo marca "trasladado" porque wb.grupo.node != nodo de casa del socio.
        foreach ($nodeOverrides as $override) {
            if ($override->getTargetNode()?->getId() !== $node->getId()) {
                continue;
            }
            $partner = $override->getPartner();
            if (isset($seenPartnerIds[$partner->getId()])) {
                continue;
            }
            $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
            if ($share === null) {
                continue;
            }
            $projection = $this->projectShareDelivery($basket, $share);
            if ($projection === null) {
                continue; // su patrón no le da cesta esta semana: nada que trasladar
            }
            $targetGroup = $override->getTargetGroup();
            $destNode = $targetGroup->getNode();
            $wb = $projection['weeklyBasket'];
            $wb->setWeeklyBasketGroup($targetGroup);
            $physical = $destNode !== null ? $this->nodeDeliveryDate->physicalDateFor($basket, $destNode) : null;
            $wb->setDeliveryDate($physical ?? $wb->getDeliveryDate());
            $deliveries[] = ['weeklyBasket' => $wb, 'items' => $projection['items']];
            $seenPartnerIds[$partner->getId()] = true;
        }

        // Huevo extra (SANTOS-like): socios cuyo huevo es más frecuente que la cesta
        // recogen SOLO huevo en los viernes intermedios. Es patrón base (lo hace
        // materializeExtraEggDeliveries en el camino de piedra), no un shift, así que
        // la proyección lo incluye para ser fiel. Se omite a quien ya tiene línea esta
        // semana (su entrega cesta+huevo del día mensual) o sale por un shift.
        foreach ($shareRepo->findActiveSharesWithEggsForBasket($basket) as $share) {
            $partner = $share->getPartner();
            if (!$this->partnerBelongsToNode($partner, $node)
                || isset($seenPartnerIds[$partner->getId()])
                || in_array($partner->getId(), $wholeOutgoingPartnerIds, true)
                || isset($relocatedOutPartnerIds[$partner->getId()])
            ) {
                continue;
            }
            if (!$this->eggResolver->delivers($share, $basket)) {
                continue;
            }
            $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
            if ($physicalDate === null) {
                continue;
            }
            $wb = (new WeeklyBasket())
                ->setBasket($basket)
                ->setPartner($partner)
                ->setWeeklyBasketStatus(null)
                ->setBasketShare($this->em->getRepository(BasketShare::class)->find(self::SHARE_ONLY_EGG))
                ->setWeeklyBasketGroup($partner->getWeeklyBasketGroup())
                ->setAmount(0)
                ->setDeliveryDate($physicalDate)
                ->setPartnerBasketShare($share);
            $deliveries[] = ['weeklyBasket' => $wb, 'items' => $this->composer->computeItems($wb, $share, $basket)];
            $seenPartnerIds[$partner->getId()] = true;
        }

        // Intents POR COMPONENTE (SANTOS mueve solo la verdura): ajustan la
        // composición sin sacar al socio. Pase final, como applyComponentIntents en
        // el camino de escritura, para que el dibujo refleje el "muevo solo la
        // verdura" igual que lo hará el materializado al congelarse.
        return $this->applyComponentIntentsToProjection($node, $basket, $outgoing, $incoming, $deliveries);
    }

    /**
     * Aplica a la PROYECCIÓN los intents POR COMPONENTE de los socios del nodo, en
     * paralelo a {@see applyComponentIntents} del camino de escritura pero sobre las
     * líneas proyectadas (arrays {component, amount}) en vez de WeeklyBasketItem
     * persistidos. Reusa la MISMA fuente de cantidades
     * ({@see WeeklyBasketComposer::amountForComponent}) y la MISMA regla de
     * realineado verdura-sobre-solo-huevo, así que dibujo y piedra coinciden (lo
     * blinda el escenario de equivalencia con mover-componente de la batería).
     *
     * Deuda DRY (proyeccion-orquestacion-dry-debt, pieza 2): la estructura del
     * pase está duplicada porque el modelo de datos difiere (array vs entidad); la
     * lógica sutil (qué cantidad, qué realineado) sí es compartida.
     *
     * @param PartnerDeliveryShift[] $outgoing
     * @param PartnerDeliveryShift[] $incoming
     * @param list<array{weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>}> $deliveries
     * @return list<array{weeklyBasket: WeeklyBasket, items: array<int, array{component: BasketComponent, amount: string}>}>
     */
    private function applyComponentIntentsToProjection(
        Node $node,
        Basket $basket,
        array $outgoing,
        array $incoming,
        array $deliveries,
    ): array {
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);

        // partnerId => posición en $deliveries, para localizar la entrega a ajustar.
        $indexByPartner = [];
        foreach ($deliveries as $i => $delivery) {
            $pid = $delivery['weeklyBasket']->getPartner()?->getId();
            if ($pid !== null) {
                $indexByPartner[$pid] = $i;
            }
        }

        // SALIENTES por componente: quitar ese componente de la entrega del socio.
        foreach ($outgoing as $shift) {
            if ($shift->isWholeDelivery() || !$this->partnerBelongsToNode($shift->getPartner(), $node)) {
                continue;
            }
            $i = $indexByPartner[$shift->getPartner()->getId()] ?? null;
            if ($i === null) {
                continue;
            }
            $componentId = $shift->getComponent()->getId();
            $deliveries[$i]['items'] = array_values(array_filter(
                $deliveries[$i]['items'],
                static fn (array $item): bool => $item['component']->getId() !== $componentId,
            ));
        }

        // ENTRANTES por componente: añadir ese componente (creando la entrega si el
        // socio no recogía esta semana), con la cantidad de su share activo.
        foreach ($incoming as $shift) {
            if ($shift->isWholeDelivery() || !$this->partnerBelongsToNode($shift->getPartner(), $node)) {
                continue;
            }
            $partner = $shift->getPartner();
            $share = $shareRepo->findActiveForPartner($partner, $basket->getDate());
            if ($share === null) {
                continue;
            }
            $component = $shift->getComponent();
            $amount = $this->composer->amountForComponent($share, $component);
            if ($amount === null) {
                continue;
            }

            $i = $indexByPartner[$partner->getId()] ?? null;
            if ($i === null) {
                // El componente aterriza en una semana sin entrega del socio: crear
                // la entrega con su modalidad y la fecha física de su nodo (igual que
                // createWeeklyBasketForShiftDestination en escritura).
                $node_ = $partner->getWeeklyBasketGroup()?->getNode();
                $date = $node_ !== null
                    ? ($this->nodeDeliveryDate->physicalDateFor($basket, $node_) ?? $basket->getDate())
                    : $basket->getDate();
                $deliveries[] = [
                    'weeklyBasket' => $this->newWeeklyBasketForShare($basket, $share, $date, null),
                    'items' => [],
                ];
                $i = array_key_last($deliveries);
                $indexByPartner[$partner->getId()] = $i;
            } elseif ($component->getId() === BasketComponent::ID_VEGETABLES
                && ($deliveries[$i]['weeklyBasket']->getBasketShare()?->getDeliveredBasketWeight() ?? 0.0) <= 0.0
            ) {
                // Verdura aterrizando en una entrega "solo-huevo": realinear la
                // modalidad del WB a la real del socio (mismo criterio que escritura).
                $deliveries[$i]['weeklyBasket']
                    ->setBasketShare($share->getBasketShare())
                    ->setAmount($share->getAmount())
                    ->setPartnerBasketShare($share);
            }

            // Reemplazar o añadir la línea del componente entrante.
            $items = array_values(array_filter(
                $deliveries[$i]['items'],
                static fn (array $item): bool => $item['component']->getId() !== $component->getId(),
            ));
            $items[] = ['component' => $component, 'amount' => $amount];
            $deliveries[$i]['items'] = $items;
        }

        return $deliveries;
    }

    /**
     * ¿El grupo de recogida del socio cuelga del nodo dado? Filtro de pertenencia
     * al nodo para la proyección (el equivalente read-only de
     * WeeklyBasketRepository::findForNodeAndBasket del camino de piedra).
     */
    private function partnerBelongsToNode(Partner $partner, Node $node): bool
    {
        return $partner->getWeeklyBasketGroup()?->getNode()?->getId() === $node->getId();
    }

    /**
     * Convierte una entrega PROYECTADA (WeeklyBasket transitorio + sus líneas de
     * componente calculadas, sin persistir) en una {@see DeliveryLine} para el
     * shaper. Las cantidades salen de los items proyectados (con la ponderación ½
     * de compartidas y el 0 de solo-huevo ya aplicada por el composer), no de la BBDD.
     *
     * @param array<int, array{component: BasketComponent, amount: string}> $items
     */
    private function lineFromProjection(WeeklyBasket $wb, array $items): DeliveryLine
    {
        $cestas = 0.0;
        $dozens = 0.0;
        foreach ($items as $item) {
            $cid = $item['component']->getId();
            if ($cid === BasketComponent::ID_VEGETABLES) {
                $cestas += (float) $item['amount'];
            } elseif ($cid === BasketComponent::ID_EGGS) {
                $dozens += (float) $item['amount'];
            }
        }

        $partner = $wb->getPartner();
        $wbg = $wb->getWeeklyBasketGroup();
        $pbs = $wb->getPartnerBasketShare();

        return new DeliveryLine(
            nameForDelivery: $partner?->getNameForDelivery() ?? '',
            basketShareId: $wb->getBasketShare()?->getId(),
            subscribedToEggs: $pbs?->getEggAmount() !== null,
            cestas: $cestas,
            dozens: $dozens,
            groupId: $wbg?->getId(),
            groupName: $wbg?->getName(),
            groupColor: $wbg?->getColor(),
            city: $partner?->getCity()?->getName(),
            partnerId: $partner?->getId(),
            sharePartnerId: $partner?->getSharePartner()?->getId(),
            // Trasladado (override de nodo): etiqueta "Nodo · Grupo" de casa, null si no.
            relocatedFromLabel: $wb->getRelocatedFromLabel(),
        );
    }

    /**
     * Materializa (persiste) la entrega de un share en un Basket: la contraparte
     * en modo persist de projectShareDelivery. Es la base de la edición de B':
     * antes de que un socio toque una entrega "Prevista" hay que fijarla, y el
     * generador del listado luego la respeta porque ya existe (salta lo
     * existente).
     *
     * Idempotente: si ya hay un WeeklyBasket para (socio, basket) lo devuelve
     * sin duplicar ni recomponer (compose es a su vez idempotente). Crea el WB
     * con el mismo estado inicial que la generación normal y estampa sus líneas
     * de componente, para que una entrega fijada por el calendario sea
     * indistinguible de una generada por el listado.
     *
     * @param Basket             $basket
     * @param PartnerBasketShare $share
     * @return WeeklyBasket|null La entrega materializada, o null si el nodo del
     *                           socio no reparte en este Basket.
     */
    public function materializeShareDelivery(Basket $basket, PartnerBasketShare $share): ?WeeklyBasket
    {
        $existing = $this->em->getRepository(WeeklyBasket::class)
            ->findOneBy(['basket' => $basket->getId(), 'partner' => $share->getPartner()->getId()]);
        if ($existing !== null) {
            return $existing;
        }

        $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
        if ($physicalDate === null) {
            return null;
        }

        $status = $this->em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED);
        $wb = $this->newWeeklyBasketForShare($basket, $share, $physicalDate, $status);
        $this->em->persist($wb);
        $this->em->flush();

        $this->composer->compose($wb, $share, $basket);
        $this->em->flush();

        return $wb;
    }

    /**
     * Materializa WB Only-Egg para PBS cuyo egg_period es más frecuente que
     * su basket_share, en los viernes intermedios donde no toca cesta pero
     * sí toca huevo.
     *
     * Caso real: SANTOS MUÑOZ es mensual con cesta + huevo semanal. La PBS
     * principal materializa cesta+huevo en el viernes mensual; sin esto,
     * los otros 3 viernes del mes que recoge sólo huevo no aparecen en el
     * listado y descuadran contra el PDF.
     *
     * Aplica el filtro de nodo (NodeDeliveryDate) para que un partner cuyo
     * nodo no reparte esa semana no genere un WB de huevo huérfano.
     *
     * @param Basket $basket
     * @param WeeklyBasketStatus $status Estado inicial para los WB nuevos.
     * @return PartnerBasketShare[] Lista de shares cuyo huevo extra se materializó (para devolverlos al caller).
     */
    private function materializeExtraEggDeliveries(Basket $basket, WeeklyBasketStatus $status): array
    {
        /** @var \App\Repository\PartnerBasketShareRepository $shareRepo */
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);
        $weeklyBasketRepo = $this->em->getRepository(WeeklyBasket::class);

        $extras = [];
        foreach ($shareRepo->findActiveSharesWithEggsForBasket($basket) as $share) {
            if (!$this->eggResolver->delivers($share, $basket)) {
                continue;
            }
            $alreadyMaterialized = $weeklyBasketRepo->findOneBy([
                'basket' => $basket->getId(),
                'partner' => $share->getPartner()->getId(),
            ]);
            if ($alreadyMaterialized !== null) {
                continue;
            }
            if ($this->materializeOnlyEggForShare($basket, $share, $status) !== null) {
                $extras[] = $share;
            }
        }
        return $extras;
    }

    /**
     * Materializa (persiste) una entrega de SOLO HUEVO para un share en un Basket: el WB
     * con basket_share=only-egg, amount 0 y su línea de huevos. Idempotente (si ya hay WB
     * para (socio, basket) lo devuelve sin duplicar). Es la pieza común de
     * materializeExtraEggDeliveries y de materializeForPartner (reset del calendario).
     *
     * @return WeeklyBasket|null El WB only-egg, o null si el nodo no reparte ese Basket.
     */
    private function materializeOnlyEggForShare(Basket $basket, PartnerBasketShare $share, WeeklyBasketStatus $status): ?WeeklyBasket
    {
        $partner = $share->getPartner();
        $existing = $this->em->getRepository(WeeklyBasket::class)
            ->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
        if ($existing !== null) {
            return $existing;
        }
        $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
        if ($physicalDate === null) {
            return null;
        }

        $wb = new WeeklyBasket();
        $wb->setBasket($basket);
        $wb->setPartner($partner);
        $wb->setWeeklyBasketStatus($status);
        $wb->setBasketShare($this->em->getRepository(BasketShare::class)->find(self::SHARE_ONLY_EGG));
        $wb->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());
        $wb->setAmount(0);
        $wb->setDeliveryDate($physicalDate);
        $wb->setPartnerBasketShare($share);
        $this->em->persist($wb);
        $this->em->flush();

        $this->composer->compose($wb, $share, $basket);
        $this->em->flush(); // asentar las líneas (el generador confiaba en un flush posterior;
                            // el reset materializa por socio y el último basket no lo tendría).

        return $wb;
    }

    /**
     * Materializa la entrega que un socio DEBE recibir en un Basket según su patrón: CESTA
     * si es candidato (modalidad/cohorte/cadencia/nodo), o SOLO-HUEVO si su huevo es más
     * frecuente que la cesta y le toca esa semana (SANTOS-like). Idempotente. Pensado para
     * el RESET del calendario de un socio: recompone lo que el listado generaría para ÉL,
     * sin tocar a los demás (a diferencia de generateForBasket, que es todo-el-listado).
     *
     * @return WeeklyBasket|null La entrega materializada, o null si esa semana no le toca nada.
     */
    public function materializeForPartner(Partner $partner, Basket $basket): ?WeeklyBasket
    {
        foreach ($this->projectForBasket($basket) as $candidate) {
            if ($candidate->getPartner()->getId() === $partner->getId()) {
                return $this->materializeShareDelivery($basket, $candidate);
            }
        }

        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share === null || !$this->eggResolver->delivers($share, $basket)) {
            return null;
        }
        $status = $this->em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED);

        return $this->materializeOnlyEggForShare($basket, $share, $status);
    }

    /**
     * Re-materializa la entrega de UN socio en un Basket para alinearla con su patrón
     * VIGENTE, sin tocar a los demás: borra su WeeklyBasket previo (con sus ítems) y, SOLO
     * si la semana sigue GENERADA (quedan WB de otros socios), vuelve a materializar su
     * patrón actual con {@see materializeForPartner} (cubre cesta Y solo-huevo SANTOS-like).
     * En una semana SIN generar no materializa: un WB suelto dispararía la rama
     * {@see reuseExistingWeeklyBaskets} y dejaría fuera al resto del listado.
     *
     * Resuelve el hueco de {@see generateForBasket}, que NO re-añade socios a un basket ya
     * generado (rama reuse): un cambio de modalidad que ENSANCHA la frecuencia (mensual→
     * semanal, etc.) perdería las entregas de las semanas donde antes no repartía. Es la
     * pieza por-socio de la cascada de {@see \App\Service\Partner\BasketModalityChanger}.
     *
     * El estado se determina mirando si quedan WB de OTROS socios tras borrar el del socio,
     * de modo que un único WB suelto del propio socio (mes por lo demás sin generar) NO
     * cuente como semana generada.
     *
     * Idempotente respecto al patrón; descarta las ediciones manuales previas de ESE socio
     * en la semana (el cambio de modalidad reinicia su patrón). No toca los PartnerDeliveryShift.
     *
     * @param Partner $partner
     * @param Basket  $basket
     * @return WeeklyBasket|null La entrega re-materializada, o null si su patrón nuevo no
     *                           reparte esa semana (o la semana no está generada).
     */
    public function rematerializeForPartner(Partner $partner, Basket $basket): ?WeeklyBasket
    {
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);

        $existing = $wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
        if ($existing !== null) {
            foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $existing]) as $item) {
                $this->em->remove($item);
            }
            $this->em->remove($existing);
            $this->em->flush();
        }

        // ¿Queda listado de OTROS socios esa semana? Si no, está sin generar y no se
        // materializa nada (lo hará la generación normal cuando toque, ya con el patrón nuevo).
        if ($wbRepo->findOneBy(['basket' => $basket->getId()]) === null) {
            return null;
        }

        return $this->materializeForPartner($partner, $basket);
    }

    /**
     * Reconcilia las entregas YA materializadas de un socio desde una fecha: por cada Basket
     * GENERADO con fecha >= $from, re-materializa la entrega del socio a su patrón VIGENTE
     * ({@see rematerializeForPartner}) — añade donde ahora reparte, quita donde ya no, y
     * re-etiqueta la modalidad. Es la cascada correcta de un cambio de modalidad: el listado
     * ya generado (típicamente las ~4 semanas que el cron adelanta) no se arregla solo porque
     * {@see generateForBasket} no re-añade socios a un basket existente. Las semanas aún SIN
     * generar no necesitan nada: la generación normal las sembrará con el patrón nuevo.
     *
     * Debe llamarse DESPUÉS de que la PBS nueva esté vigente (p. ej. tras
     * {@see \App\Service\Partner\BasketModalityChanger::applyChange}), porque lee el patrón
     * actual del socio.
     *
     * @param Partner            $partner
     * @param \DateTimeInterface $from Fecha (inclusive) desde la que reconciliar.
     * @return array<int,bool> basketId => si el socio quedó CON entrega esa semana (true) o no (false).
     */
    public function reconcilePartnerFrom(Partner $partner, \DateTimeInterface $from): array
    {
        $baskets = $this->em->createQuery(
            'SELECT DISTINCT b FROM ' . Basket::class . ' b
             JOIN ' . WeeklyBasket::class . ' wb WITH wb.basket = b
             WHERE b.date >= :from
             ORDER BY b.date ASC'
        )->setParameter('from', $from->format('Y-m-d'))->getResult();

        $result = [];
        foreach ($baskets as $basket) {
            $result[$basket->getId()] = $this->rematerializeForPartner($partner, $basket) !== null;
        }

        return $result;
    }

    /**
     * Setea la propiedad transient currentBasket en cada Partner para que
     * la vista pueda enseñar "esta es la cesta de esta semana para X".
     *
     * @param WeeklyBasket[] $weeklyBaskets
     */
    private function stampCurrentBasketOnPartners(array $weeklyBaskets, Basket $basket): void
    {
        foreach ($weeklyBaskets as $wb) {
            $stamped = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket->getId(), 'partner' => $wb->getPartner()->getId()]);
            $wb->getPartner()->setCurrentBasket($stamped);
        }
    }

    /**
     * Caso especial de las medias cestas: hay que enganchar también al
     * socio par. Si el par se dio de baja, se omite (control legacy).
     *
     * @param WeeklyBasket[] $halfBaskets
     */
    private function stampHalfBasketPairs(array $halfBaskets, Basket $basket): void
    {
        $weeklyBasketRepo = $this->em->getRepository(WeeklyBasket::class);

        foreach ($halfBaskets as $wb) {
            if ($wb->getPartner()->getIsListed()) {
                continue;
            }

            $own = $weeklyBasketRepo->findOneBy([
                'basket' => $basket->getId(),
                'partner' => $wb->getPartner()->getId(),
            ]);
            $wb->getPartner()->setCurrentBasket($own);

            if ($wb->getPartner()->getSharePartner() === null) {
                // Pair-less half basket; legacy lo deja pasar sin más.
                continue;
            }

            $pairs = $weeklyBasketRepo->findOneBy([
                'basket' => $basket->getId(),
                'partner' => $wb->getPartner()->getSharePartner()->getId(),
            ]);

            if ($pairs !== null) {
                $wb->getPartner()->getSharePartner()->setCurrentBasket($pairs);
            }
            $wb->getPartner()->getSharePartner()->setIsListed(1);
        }
    }

    /**
     * Suma el campo amount de la colección. Soporta tanto WeeklyBasket
     * (rama "ya existe") como PartnerBasketShare (rama "se crea"); ambos
     * exponen getAmount().
     *
     * @param iterable<object> $items
     */
    private function sumAmount(iterable $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) $item->getAmount();
        }
        return $total;
    }
}
