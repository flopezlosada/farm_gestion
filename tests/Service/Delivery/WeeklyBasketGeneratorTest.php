<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests de la primitiva de escritura del calendario de recogida (B'):
 * materializeShareDelivery. Contra las fixtures de db_test (socix con un
 * WeeklyBasket ya materializado).
 */
class WeeklyBasketGeneratorTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function generator(): WeeklyBasketGenerator
    {
        return static::getContainer()->get(WeeklyBasketGenerator::class);
    }

    /**
     * Sobre un Basket que ya tiene entrega para el socio, materializar devuelve
     * la existente sin duplicar (idempotencia, garantía clave de la primitiva).
     */
    public function testMaterializeIsIdempotentForExistingDelivery(): void
    {
        self::bootKernel();
        $em = $this->em();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner);
        $this->assertNotNull($share);

        $existing = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($existing, 'El socix de fixtures debería tener una entrega materializada.');
        $basket = $existing->getBasket();

        $before = count($em->getRepository(WeeklyBasket::class)->findBy(['partner' => $partner]));

        $again = $this->generator()->materializeShareDelivery($basket, $share);
        $this->assertNotNull($again);
        $this->assertSame($existing->getId(), $again->getId(), 'No debe crear una entrega nueva si ya existe.');

        $after = count($em->getRepository(WeeklyBasket::class)->findBy(['partner' => $partner]));
        $this->assertSame($before, $after, 'El recuento de entregas del socio no debe cambiar.');
    }

    /**
     * Sobre un Basket sin entrega para el socio: si el nodo reparte esa semana,
     * materializa una nueva con sus líneas de componente y es idempotente; si el
     * nodo no reparte, no persiste nada (null) — ambas ramas son correctas.
     */
    public function testMaterializeCreatesOrNoopsOnFreeBasket(): void
    {
        self::bootKernel();
        $em = $this->em();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner);
        $wbRepo = $em->getRepository(WeeklyBasket::class);

        $free = null;
        foreach ($em->getRepository(Basket::class)->findAll() as $basket) {
            if ($wbRepo->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]) === null) {
                $free = $basket;
                break;
            }
        }
        $this->assertNotNull($free, 'Las fixtures deberían dejar algún Basket sin entrega para el socio.');

        $before = count($wbRepo->findBy(['partner' => $partner]));
        $wb = $this->generator()->materializeShareDelivery($free, $share);

        if ($wb === null) {
            $this->assertSame($before, count($wbRepo->findBy(['partner' => $partner])), 'Si el nodo no reparte, no se persiste nada.');

            return;
        }

        $this->assertNotNull($wb->getId());
        $this->assertSame($before + 1, count($wbRepo->findBy(['partner' => $partner])), 'Debe crear exactamente una entrega.');
        $this->assertNotEmpty(
            $em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]),
            'La entrega materializada debe tener líneas de componente.',
        );

        $wb2 = $this->generator()->materializeShareDelivery($free, $share);
        $this->assertSame($wb->getId(), $wb2->getId(), 'Una segunda llamada no debe duplicar.');
    }

    /**
     * Guarda clave de la cascada de cambio de modalidad ({@see WeeklyBasketGenerator::rematerializeForPartner}):
     * sobre una semana SIN generar (ningún WeeklyBasket de ningún socio) NO se materializa nada.
     * Un WB suelto en una semana sin generar dispararía la rama reuseExistingWeeklyBaskets y
     * dejaría fuera al resto del listado al generarlo. Determinista: no depende de cohorte ni
     * cadencia (la guarda corta antes de proyectar), solo de que exista un Basket vacío.
     */
    public function testRematerializeDoesNotMaterializeOnUngeneratedWeek(): void
    {
        self::bootKernel();
        $em = $this->em();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        $wbRepo = $em->getRepository(WeeklyBasket::class);

        // Basket dedicado sin ninguna entrega: autocontenido para no depender del
        // estado de db_test (que no hace rollback entre tests y puede dejar todos
        // los Baskets de fixtures ya generados). Fecha imposible de colisionar.
        $ungenerated = new Basket();
        $ungenerated->setDate(new \DateTime('2099-12-25'));
        $ungenerated->setWeek(52);
        $ungenerated->setAmount(1);
        $em->persist($ungenerated);
        $em->flush();

        $this->assertNull(
            $wbRepo->findOneBy(['basket' => $ungenerated->getId()]),
            'El Basket recién creado no debe tener ninguna entrega.',
        );

        $result = $this->generator()->rematerializeForPartner($partner, $ungenerated);

        $this->assertNull($result, 'No debe materializar en una semana sin generar.');
        $this->assertNull(
            $wbRepo->findOneBy(['basket' => $ungenerated->getId()]),
            'No debe quedar ningún WeeklyBasket suelto en la semana sin generar.',
        );
    }

    /**
     * La cesta QUINCENAL COMPARTIDA (basket_share 6) debe entrar en los candidatos del
     * Basket en su semana de cohorte. Era un hueco: el generador sólo consultaba bs 1/2/3/4/5,
     * así que las compartidas quincenal/mensual (6/7) nunca materializaban su media cesta.
     *
     * Determinista sin acoplar al ancla del resolver: se crea el Basket, se pregunta al
     * BiweeklyCohortResolver qué cohorte le toca, y se alinea el socio a ESA cohorte (y otro
     * a la contraria como control negativo). El socio no tiene nodo → cae en la rama
     * `n.id IS NULL AND delivery_group = :cohort` del finder node-aware.
     */
    public function testBiweeklySharedCestaEsCandidataEnSuSemanaDeCohorte(): void
    {
        self::bootKernel();
        $em = $this->em();

        $basket = new Basket();
        $basket->setDate(new \DateTime('2099-12-25'));
        $basket->setWeek(52);
        $basket->setAmount(1);
        $em->persist($basket);
        $em->flush();

        $resolver = static::getContainer()->get(BiweeklyCohortResolver::class);
        $cohort = $resolver->cohortForBasket($basket);
        $other = $cohort === PartnerBasketShare::DELIVERY_GROUP_A
            ? PartnerBasketShare::DELIVERY_GROUP_B
            : PartnerBasketShare::DELIVERY_GROUP_A;

        $quincenalShared = $em->getRepository(BasketShare::class)->find(6);
        $this->assertNotNull($quincenalShared, 'El catálogo debe tener la quincenal compartida (id 6).');

        $inCohort = $this->makeSharedQuincenal($em, $quincenalShared, $cohort, 'QC EnCohorte');
        $offCohort = $this->makeSharedQuincenal($em, $quincenalShared, $other, 'QC FueraCohorte');
        $em->flush();

        $candidates = $this->generator()->projectForBasket($basket);
        $ids = array_map(static fn (PartnerBasketShare $s): int => $s->getId(), $candidates);

        $this->assertContains($inCohort->getId(), $ids, 'La quincenal compartida (bs=6) de la cohorte que toca debe ser candidata.');
        $this->assertNotContains($offCohort->getId(), $ids, 'La quincenal compartida de la otra cohorte NO debe repartir esa semana.');
    }

    /**
     * Al finalizar una media cesta (bs=4) con baja ya vencida, la generación del listado
     * NO debe crashear y debe romper el vínculo share_partner en AMBOS sentidos. Regresión
     * de un bug severo: finalizeExpiredShares llamaba a Partner::getBasketShare() (método
     * inexistente) → fatal que tumbaba TODO el listado del Basket al vencer una compartida.
     * Determinista: end_date 2020-01-01 (siempre pasada) → siempre la coge findFinalized.
     */
    public function testFinalizarMediaCestaRompeElVinculoSinCrashear(): void
    {
        self::bootKernel();
        $em = $this->em();

        $half = $em->getRepository(BasketShare::class)->find(4);
        $this->assertNotNull($half, 'El catálogo debe tener la semanal compartida (id 4).');

        $leaving = new Partner();
        $leaving->setName('Media Baja');
        $staying = new Partner();
        $staying->setName('Media Sigue');
        $em->persist($leaving);
        $em->persist($staying);
        $leaving->setSharePartner($staying);
        $staying->setSharePartner($leaving);

        $leavingShare = $this->makeHalfShare($em, $leaving, $half, new \DateTime('2020-01-01')); // baja vencida, is_active sigue 1
        $this->makeHalfShare($em, $staying, $half, null);
        $em->flush();

        $basket = new Basket();
        $basket->setDate(new \DateTime('2099-11-27'));
        $basket->setWeek(48);
        $basket->setAmount(1);
        $em->persist($basket);
        $em->flush();

        // No debe lanzar (antes: fatal "undefined method getBasketShare").
        $this->generator()->generateForBasket($basket);

        $this->assertFalse($leavingShare->getIsActive(), 'La media cesta con baja vencida queda inactiva.');
        $this->assertNull($leaving->getSharePartner(), 'Se rompe el vínculo en quien causa baja.');
        $this->assertNull($staying->getSharePartner(), 'Y en su pareja (back-link).');
    }

    private function makeHalfShare(
        EntityManagerInterface $em,
        Partner $partner,
        BasketShare $half,
        ?\DateTimeInterface $endDate,
    ): PartnerBasketShare {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($half);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2019-01-01'));
        $share->setEndDate($endDate);
        $em->persist($share);

        return $share;
    }

    private function makeSharedQuincenal(
        EntityManagerInterface $em,
        BasketShare $quincenalShared,
        string $cohort,
        string $name,
    ): PartnerBasketShare {
        $partner = new Partner();
        $partner->setName($name);
        $em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($quincenalShared);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setDeliveryGroup($cohort);
        $em->persist($share);

        return $share;
    }
}
