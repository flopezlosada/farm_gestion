<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
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
}
