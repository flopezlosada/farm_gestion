<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Service\Delivery\DeliveryShiftApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La cesta EXTRA de una semana VIAJA con la cesta al moverla de día.
 *
 * Es la segunda puerta del mismo agujero que el "no recoge" (ver
 * {@see SkipClearsBasketExtraTest}): el override se quedaba en el día de origen. En una semana
 * ya generada eso significaba contarla DOS veces —viajaba dentro de la composición del destino
 * y seguía dibujándose en el origen—, o sea una cesta de más en el listado impreso.
 */
class MoveCarriesBasketExtraTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testMoverLaCestaSeLlevaSuCestaExtraAlDestino(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('MoveExtra ' . uniqid('', true));
        $em->persist($partner);

        $origin = (new Basket())->setDate(new \DateTime('2099-11-06'))->setWeek(45)->setAmount(1);
        $target = (new Basket())->setDate(new \DateTime('2099-11-13'))->setWeek(46)->setAmount(1);
        $em->persist($origin);
        $em->persist($target);

        $em->persist(new PartnerBasketExtra($partner, $origin, $vegetables, '2.00'));
        $em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)
            ->move($partner, $origin, $target, 'test:move-extra');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $origin = $em->getRepository(Basket::class)->find($origin->getId());
        $target = $em->getRepository(Basket::class)->find($target->getId());
        $extraRepo = $em->getRepository(PartnerBasketExtra::class);

        $this->assertSame(
            [],
            $extraRepo->findForPartnerAndBasket($partner, $origin),
            'El día de origen queda sin extras: si no, la proyección lo dibujaría como cesta fantasma.',
        );

        $moved = $extraRepo->findForPartnerAndBasket($partner, $target);
        $this->assertCount(1, $moved, 'La cesta extra debe aparecer en el día destino.');
        $this->assertSame(2.0, (float) $moved[0]->getAmount(), 'Viaja con su cantidad, no con la de patrón.');

        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner, 'fromBasket' => $origin]);
        $this->assertNotNull($shift, 'El cambio de día debe haberse aplicado igualmente.');
        $this->assertSame($target->getId(), $shift->getToBasket()?->getId());

        $this->cleanUp($em, $partner, [$origin, $target]);
    }

    /**
     * Mover al MISMO día es un no-op y no debe menear el extra: quitarlo y volverlo a poner
     * dejaría dos apuntes espurios en el histórico del socio.
     */
    public function testMoverAlMismoDiaNoTocaLaCestaExtra(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $partner = (new Partner())->setName('MoveExtraSame ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-11-20'))->setWeek(47)->setAmount(1);
        $em->persist($basket);
        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)
            ->move($partner, $basket, $basket, 'test:move-same-day');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $basket = $em->getRepository(Basket::class)->find($basket->getId());

        $extras = $em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket);
        $this->assertCount(1, $extras, 'El extra sigue donde estaba.');
        $this->assertSame(
            [],
            $em->getRepository(PartnerEvent::class)->findBy(['partner' => $partner]),
            'Un no-op no deja rastro en el histórico.',
        );

        $this->cleanUp($em, $partner, [$basket]);
    }

    /**
     * Borra lo que el test creó (db_test no tiene rollback por test).
     *
     * @param Basket[] $baskets
     */
    private function cleanUp(EntityManagerInterface $em, Partner $partner, array $baskets): void
    {
        foreach ($baskets as $basket) {
            foreach ($em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket) as $extra) {
                $em->remove($extra);
            }
        }
        foreach ($em->getRepository(PartnerDeliveryShift::class)->findBy(['partner' => $partner]) as $shift) {
            $em->remove($shift);
        }
        foreach ($em->getRepository(PartnerEvent::class)->findBy(['partner' => $partner]) as $event) {
            $em->remove($event);
        }
        $em->flush();

        $em->remove($partner);
        foreach ($baskets as $basket) {
            $em->remove($basket);
        }
        $em->flush();
    }
}
