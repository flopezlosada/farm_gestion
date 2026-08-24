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
 * "No recoge" una semana deja el día a CERO, cestas extra incluidas.
 *
 * Regresión del lío de tres pasos que reportó administración: trasladar una cesta SUMANDO a
 * un día que ya recoge (ese día pasa a llevar 2 cestas) y marcar después "no recoge" solo
 * quitaba una — el override de extra sobrevivía al skip y la proyección lo resucitaba. El día
 * quedaba con una cesta fantasma que además no se podía mover (su día ya tenía el skip
 * saliente), así que no había salida.
 *
 * Se prueba contra el servicio del CONTENEDOR a propósito: así el test cubre también que
 * Symfony resuelve la interfaz {@see \App\Service\Delivery\ExtraBasketRemover} a su único
 * implementador, que es de lo que depende el applier.
 */
class SkipClearsBasketExtraTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Aparcar la entrega ENTERA de una semana borra sus overrides de cesta extra.
     */
    public function testApplySkipIntentQuitaLaCestaExtraDeEsaSemana(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('SkipExtra ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-10-02'))->setWeek(40)->setAmount(1);
        $em->persist($basket);

        // El día lleva una cesta extra (la que dejaría un "trasladar sumando").
        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $basket, null, 'test:skip-extra');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $basket = $em->getRepository(Basket::class)->find($basket->getId());

        $this->assertSame(
            [],
            $em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket),
            'El "no recoge" de la entrega entera debe dejar el día a cero: sin cestas extra.',
        );

        $skip = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner, 'fromBasket' => $basket]);
        $this->assertNotNull($skip, 'Debe quedar el intent de "no recoge" (la cesta aparcada).');
        $this->assertTrue($skip->isSkip(), 'El intent es un skip (sin destino).');

        $this->cleanUp($em, $partner, $basket);
    }

    /**
     * Quitar UN COMPONENTE (no la entrega entera) NO toca las cestas extra: el día sigue
     * teniendo entrega, así que el añadido puntual sigue siendo suyo.
     */
    public function testQuitarUnComponenteNoTocaLaCestaExtra(): void
    {
        self::bootKernel();
        $em = $this->em();

        $eggs = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($eggs);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('SkipComp ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-10-09'))->setWeek(41)->setAmount(1);
        $em->persist($basket);

        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        // Intent de "quitar los huevos" de esa semana (component != null).
        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $basket, $eggs, 'test:skip-component');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $basket = $em->getRepository(Basket::class)->find($basket->getId());

        $this->assertCount(
            1,
            $em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket),
            'Quitar un componente no vacía el día: la cesta extra se queda.',
        );

        $this->cleanUp($em, $partner, $basket);
    }

    /**
     * Borra lo que el test creó (db_test no tiene rollback por test).
     */
    private function cleanUp(EntityManagerInterface $em, Partner $partner, Basket $basket): void
    {
        foreach ($em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket) as $extra) {
            $em->remove($extra);
        }
        foreach ($em->getRepository(PartnerDeliveryShift::class)->findBy(['partner' => $partner]) as $shift) {
            $em->remove($shift);
        }
        foreach ($em->getRepository(PartnerEvent::class)->findBy(['partner' => $partner]) as $event) {
            $em->remove($event);
        }
        $em->flush();

        $em->remove($partner);
        $em->remove($basket);
        $em->flush();
    }
}
