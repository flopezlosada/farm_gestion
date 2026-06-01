<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
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
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
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

        // El proyector aún no tiene consumidor en el contenedor (el controller
        // llega en un paso posterior), así que se construye a mano con el
        // generador, que sí es un servicio vivo.
        $projector = new DeliveryCalendarProjector(
            $em,
            static::getContainer()->get(WeeklyBasketGenerator::class),
            static::getContainer()->get(WeeklyBasketComposer::class),
            static::getContainer()->get(EggDeliveryResolver::class),
        );
        $slots = $projector->projectMonth($partner, (int) $date->format('Y'), (int) $date->format('n'));

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
