<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Entity\WeeklyBasketStatus;
use App\Entity\BasketComponent;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\WeeklyBasketComposer;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test funcional del proyector de calendario de recogida (B') contra las
 * fixtures de db_test: el socix quincenal con un WeeklyBasket ya materializado.
 */
class DeliveryCalendarProjectorTest extends KernelTestCase
{
    private const ID_EGGS = 2;
    private const STATUS_PICKED = 1;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function makeProjector(EntityManagerInterface $em): DeliveryCalendarProjector
    {
        return new DeliveryCalendarProjector(
            $em,
            static::getContainer()->get(WeeklyBasketGenerator::class),
            static::getContainer()->get(WeeklyBasketComposer::class),
            static::getContainer()->get(EggDeliveryResolver::class),
            static::getContainer()->get(\App\Repository\DeliveryExceptionRepository::class),
            static::getContainer()->get(\App\Service\Delivery\NodeDeliveryDate::class),
        );
    }

    /**
     * Regresión (bug Antía, 2026-07-23): una cesta extra de huevos sobre una entrega YA
     * materializada NO debe doblarse en el calendario. La piedra (WeeklyBasket) ya lleva el
     * extra cascadeado; el proyector no debe volver a sumarlo. Antes, ½ docena extra se
     * mostraba como 1 docena.
     */
    public function testExtraSobreEntregaMaterializadaNoSeDobla(): void
    {
        self::bootKernel();
        $em = $this->em();

        $eggs = $em->getRepository(BasketComponent::class)->find(self::ID_EGGS);
        $status = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED);
        $this->assertNotNull($eggs);
        $this->assertNotNull($status);

        $partner = (new Partner())->setName('ProjExtra ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-08-07'))->setWeek(32)->setAmount(1);
        $em->persist($basket);

        // Entrega materializada con ½ docena de huevos (la piedra YA incluye el extra).
        $wb = (new WeeklyBasket())
            ->setBasket($basket)
            ->setPartner($partner)
            ->setWeeklyBasketStatus($status)
            ->setAmount(0)
            ->setDeliveryDate($basket->getDate());
        $em->persist($wb);
        $item = (new WeeklyBasketItem())->setWeeklyBasket($wb)->setBasketComponent($eggs)->setAmount('0.50');
        $em->persist($item);

        // El override de extra de ½ docena que originó esa piedra.
        $em->persist(new PartnerBasketExtra($partner, $basket, $eggs, '0.50'));
        $em->flush();

        $slots = $this->makeProjector($em)->projectMonth($partner, 2099, 8);

        $eggSlot = null;
        foreach ($slots as $slot) {
            if ($slot['basket']->getId() === $basket->getId()) {
                $eggSlot = $slot;
                break;
            }
        }
        $this->assertNotNull($eggSlot, 'Debe haber slot para la semana de la entrega materializada.');

        $dozens = null;
        foreach ($eggSlot['items'] as $line) {
            if ($line['component']->getId() === self::ID_EGGS) {
                $dozens = (float) $line['amount'];
            }
        }
        $this->assertSame(0.5, $dozens, 'La ½ docena extra ya está en la piedra; el proyector no debe duplicarla.');
    }

    /**
     * Regresión: una cesta extra NO puede pisar el slot de PAPELERA de una semana marcada
     * "no recoge". Antes el extra agarraba ese slot por índice, le ponía skipped=false y le
     * sumaba su delta: la cesta aparcada se convertía en entrega normal y desaparecía de la
     * papelera, sin forma de recuperarla. Ahora el extra se dibuja en su propio slot y la
     * tarjeta aparcada sobrevive: son dos hechos distintos del mismo día.
     */
    public function testExtraNoPisaElSlotDePapeleraDeUnaSemanaSaltada(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('ProjSkipExtra ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-09-04'))->setWeek(36)->setAmount(1);
        $em->persist($basket);

        // "No recoge" esa semana (intent sin destino) + una cesta extra sobre el mismo día.
        $skip = new PartnerDeliveryShift($partner, $basket, null);
        $em->persist($skip);
        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        $slots = $this->makeProjector($em)->projectMonth($partner, 2099, 9);

        $tray = [];
        $grid = [];
        foreach ($slots as $slot) {
            if ($slot['basket']->getId() !== $basket->getId()) {
                continue;
            }
            if ($slot['skipped']) {
                $tray[] = $slot;
            } else {
                $grid[] = $slot;
            }
        }

        $this->assertCount(1, $tray, 'La cesta aparcada debe seguir en la papelera (slot skipped).');
        $this->assertSame([], $tray[0]['items'], 'La cesta aparcada no lleva nada: el extra no se le suma.');
        $this->assertCount(1, $grid, 'La cesta extra debe dibujarse en su propio slot de rejilla.');
        $this->assertTrue($grid[0]['extra'] ?? false, "El slot de la extra debe venir marcado con 'extra'.");

        $vegAmount = null;
        foreach ($grid[0]['items'] as $line) {
            if ($line['component']->getId() === BasketComponent::ID_VEGETABLES) {
                $vegAmount = (float) $line['amount'];
            }
        }
        $this->assertSame(1.0, $vegAmount, 'El slot de rejilla lleva la cesta extra.');

        // db_test no tiene rollback por test: se limpia lo creado aquí.
        foreach ($em->getRepository(PartnerBasketExtra::class)->findBy(['partner' => $partner]) as $extra) {
            $em->remove($extra);
        }
        $em->remove($skip);
        $em->flush();
        $em->remove($partner);
        $em->remove($basket);
        $em->flush();
    }

    /**
     * El mes que contiene la entrega materializada del socix la incluye como
     * slot con source 'materialized' (regla 1: lo materializado gana sobre la
     * proyección).
     */
    public function testMaterializedDeliveryWinsInMonth(): void
    {
        self::bootKernel();
        $em = $this->em();

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner, 'Fixtures sin el socix de prueba.');

        $materialized = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($materialized, 'El socix de fixtures debería tener una entrega materializada.');

        $date = $materialized->getBasket()->getDate();

        $slots = $this->makeProjector($em)->projectMonth($partner, (int) $date->format('Y'), (int) $date->format('n'));

        $this->assertNotEmpty($slots, 'El mes con la entrega materializada no puede salir vacío.');

        $materializedSlot = null;
        foreach ($slots as $slot) {
            if ($slot['source'] === 'materialized' && $slot['weeklyBasket']->getId() === $materialized->getId()) {
                $materializedSlot = $slot;
                break;
            }
        }
        $this->assertNotNull($materializedSlot, 'La entrega materializada del socix debe aparecer como slot materializado.');

        // Claves que la pantalla de B' consume para pintar las acciones.
        $this->assertArrayHasKey('skipped', $materializedSlot);
        $this->assertIsBool($materializedSlot['skipped']);
        $this->assertArrayHasKey('available', $materializedSlot);
        $this->assertContainsOnlyInstancesOf(
            BasketComponent::class,
            $materializedSlot['available'],
            "'available' debe ser una lista de BasketComponent (el universo de toggles).",
        );
    }

    /**
     * La próxima entrega que anuncia el panel del socix ignora las semanas que
     * dejó sin recoger, y sí ve la cesta extra que puso ese mismo día.
     *
     * Las dos caras del mismo montaje: un "no recoge" deja en el calendario un
     * slot de papelera (skipped) que NO es una entrega. Anunciarlo como "tu
     * próxima cesta" mandaría a alguien al punto de recogida un día en el que
     * nadie le va a dar nada — y es fácil que vuelva, porque el slot existe y
     * tiene fecha.
     */
    public function testLaProximaEntregaIgnoraLaPapeleraYVeLaCestaExtra(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('ProjNext ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-10-02'))->setWeek(40)->setAmount(1);
        $em->persist($basket);

        // Sólo un "no recoge": ese día no hay nada que recoger.
        $em->persist(new PartnerDeliveryShift($partner, $basket, null));
        $em->flush();

        $projector = $this->makeProjector($em);
        $desde = new \DateTime('2099-10-01');

        $this->assertNull(
            $projector->nextDelivery($partner, $desde, 2),
            'Una semana aparcada en la papelera no es una entrega: no puede anunciarse como la próxima cesta.',
        );

        // Ahora sí hay algo ese día: una cesta extra puntual.
        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();
        $em->clear();

        $next = $this->makeProjector($this->em())->nextDelivery(
            $this->em()->getRepository(Partner::class)->find($partner->getId()),
            $desde,
            2
        );

        $this->assertNotNull($next, 'La cesta extra de ese día sí es una entrega y debe anunciarse.');
        $this->assertSame($basket->getId(), $next['basket']->getId());
        $this->assertFalse($next['skipped']);
    }
}
