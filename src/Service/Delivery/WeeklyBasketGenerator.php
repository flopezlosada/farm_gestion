<?php

namespace App\Service\Delivery;

use App\Custom\WeekOfMonth;
use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
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
                // Comportamiento legacy preservado: las cestas compartidas dejan
                // de tener pareja cuando una de las dos se finaliza.
                $partnerShare = $share->getPartner()->getBasketShare();
                $share->getPartner()->setSharePartner(null);
                $partnerShare->setSharePartner(null);
                $this->em->persist($partnerShare);
                $this->em->persist($share->getPartner());
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
        $biweekly = $weeklyRepo->findBy(['basket_share' => self::SHARE_BIWEEKLY, 'basket' => $basket]);
        $monthly = $weeklyRepo->findBy(['basket_share' => self::SHARE_MONTHLY, 'basket' => $basket]);
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
        $outgoingPartnerIds = array_map(static fn (PartnerDeliveryShift $s) => $s->getPartner()->getId(), $outgoing);

        if (!empty($outgoingPartnerIds)) {
            $excludeOutgoing = static function (array $shares) use ($outgoingPartnerIds): array {
                return array_values(array_filter(
                    $shares,
                    static fn ($share) => !in_array($share->getPartner()->getId(), $outgoingPartnerIds, true),
                ));
            };
            $weekly = $excludeOutgoing($weekly);
            $half = $excludeOutgoing($half);
            $biweekly = $excludeOutgoing($biweekly);
            $monthly = $excludeOutgoing($monthly);
            $onlyEgg = $excludeOutgoing($onlyEgg);
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

            $wb = new WeeklyBasket();
            $wb->setBasket($basket);
            $wb->setPartner($share->getPartner());
            $wb->setWeeklyBasketStatus($status);
            $wb->setBasketShare($share->getBasketShare());
            $wb->setWeeklyBasketGroup($share->getPartner()->getWeeklyBasketGroup());
            $wb->setAmount($share->getAmount());
            $wb->setDeliveryDate($physicalDate);
            $this->em->persist($wb);
            $this->em->flush();

            $stamped = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket->getId(), 'partner' => $share->getPartner()->getId()]);
            $share->getPartner()->setCurrentBasket($stamped);
        }

        $incoming = $shiftRepo->findAllIncomingToBasket($basket);
        foreach ($incoming as $shift) {
            $partner = $shift->getPartner();
            $existing = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
            if ($existing !== null) {
                continue;
            }
            $this->shiftApplier->createWeeklyBasketForShiftDestination($partner, $basket);
            $this->em->flush();
        }

        $extraEggOnly = $this->materializeExtraEggDeliveries($basket, $status);
        $onlyEgg = array_merge($onlyEgg, $extraEggOnly);

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
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
        $onlyEggShare = $this->em->getRepository(BasketShare::class)->find(self::SHARE_ONLY_EGG);

        $extras = [];
        foreach ($shareRepo->findActiveSharesWithEggsForBasket($basket) as $share) {
            if (!$this->eggResolver->delivers($share, $basket)) {
                continue;
            }
            $partner = $share->getPartner();
            $alreadyMaterialized = $weeklyBasketRepo->findOneBy([
                'basket' => $basket->getId(),
                'partner' => $partner->getId(),
            ]);
            if ($alreadyMaterialized !== null) {
                continue;
            }
            $physicalDate = $this->resolvePhysicalDeliveryDate($basket, $share);
            if ($physicalDate === null) {
                continue;
            }

            $wb = new WeeklyBasket();
            $wb->setBasket($basket);
            $wb->setPartner($partner);
            $wb->setWeeklyBasketStatus($status);
            $wb->setBasketShare($onlyEggShare);
            $wb->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());
            $wb->setAmount(0);
            $wb->setDeliveryDate($physicalDate);
            $this->em->persist($wb);
            $this->em->flush();
            $extras[] = $share;
        }
        return $extras;
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
