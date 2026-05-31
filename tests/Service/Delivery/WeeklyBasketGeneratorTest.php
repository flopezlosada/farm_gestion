<?php

namespace App\Tests\Service\Delivery;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
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
}
