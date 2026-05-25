<?php

namespace App\Service\Delivery;

use App\Custom\WeekOfMonth;
use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
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
 * Lo que NO hace todavía: consumir DeliveryException, balancear A/B
 * vía PartnerBasketShare.delivery_group, emitir PartnerEvent.
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
    ) {
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

        $this->stampCurrentBasketOnPartners(
            array_merge($weekly, $biweekly, $monthly, $onlyEgg),
            $basket
        );
        $this->stampHalfBasketPairs($half, $basket);

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
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
        $shareRepo = $this->em->getRepository(PartnerBasketShare::class);

        $weekly = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_WEEKLY, 1, $basket);
        $half = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_HALF, 1, $basket);
        $biweekly = $shareRepo->findBasketPartnersBiweeklyAndCity($basket, self::SHARE_BIWEEKLY, 1);
        $monthly = $shareRepo->findBasketPartnersMonthlyAndCity($basket, self::SHARE_MONTHLY, $dayOrder);

        $onlyEggWeekly = $shareRepo->findBasketPartnersByTypeAndCity(self::SHARE_ONLY_EGG, 1, $basket, true);
        $onlyEggBiweekly = $shareRepo->findBasketPartnersBiweeklyAndCity($basket, self::SHARE_ONLY_EGG, 1, true);
        $onlyEggMonthly = $shareRepo->findBasketPartnersMonthlyAndCity($basket, self::SHARE_ONLY_EGG, $dayOrder, true);
        $onlyEgg = array_merge($onlyEggWeekly, $onlyEggBiweekly, $onlyEggMonthly);

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
            $wb = new WeeklyBasket();
            $wb->setBasket($basket);
            $wb->setPartner($share->getPartner());
            $wb->setWeeklyBasketStatus($status);
            $wb->setBasketShare($share->getBasketShare());
            $wb->setWeeklyBasketGroup($share->getPartner()->getWeeklyBasketGroup());
            $wb->setAmount($share->getAmount());
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

        return [$weekly, $half, $biweekly, $monthly, $onlyEgg];
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
