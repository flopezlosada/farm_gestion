<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\DeliveryShiftApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Premisa de la feature "mover los huevos en cestas compartidas" (Mila/Gladys): el MOTOR
 * (DeliveryShiftApplier::moveComponent) mueve SOLO los huevos de un socio compartido sin
 * tocar su cesta (verdura), que en una compartida la marca la alternancia con el otro hogar.
 *
 * R1 (bloqueo de compartidas) vive en el controlador, no aquí: los huevos no se comparten
 * (cada hogar los suyos, en su propio PartnerBasketShare), así que el applier —agnóstico al
 * socio— debe tratarlos como los de cualquiera. Este test lo blinda antes de abrir la puerta
 * en la UI.
 */
class SharedEggMoveTest extends KernelTestCase
{
    private const ID_VEGETABLES = 1;
    private const ID_EGGS = 2;
    private const STATUS_PICKED = 1;
    private const SHARE_BIWEEKLY_SHARED = 6;
    private const EGG_AMOUNT_HALF_DOZEN = 1; // getDozens() = id * 0.5 → 0.5
    private const EGG_PERIOD_BIWEEKLY = 2;

    public function testMoverLosHuevosDeUnCompartidoNoTocaSuCesta(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $veg = $em->getRepository(BasketComponent::class)->find(self::ID_VEGETABLES);
        $eggs = $em->getRepository(BasketComponent::class)->find(self::ID_EGGS);
        $picked = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED);
        $sharedShare = $em->getRepository(BasketShare::class)->find(self::SHARE_BIWEEKLY_SHARED);
        $eggAmount = $em->getRepository(EggAmount::class)->find(self::EGG_AMOUNT_HALF_DOZEN);
        $eggPeriod = $em->getRepository(EggPeriod::class)->find(self::EGG_PERIOD_BIWEEKLY);
        foreach ([$veg, $eggs, $picked, $sharedShare, $eggAmount, $eggPeriod] as $cat) {
            $this->assertNotNull($cat, 'Catálogo de db_test incompleto para el test.');
        }

        // Pareja compartida (Mila/Gladys): dos socios enlazados mutuamente por sharePartner.
        $mila = (new Partner())->setName('MilaTest ' . uniqid('', true));
        $gladys = (new Partner())->setName('GladysTest ' . uniqid('', true));
        $em->persist($mila);
        $em->persist($gladys);
        $mila->setSharePartner($gladys);
        $gladys->setSharePartner($mila);

        // Cada hogar su PROPIO PBS con sus PROPIOS huevos (los huevos no se comparten).
        $share = new PartnerBasketShare();
        $share->setPartner($mila);
        $share->setBasketShare($sharedShare);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setVegetablesBasketAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setEggAmount($eggAmount);
        $share->setEggPeriod($eggPeriod);
        $share->setDeliveryGroup('B');
        $share->setStartDate(new \DateTime('2020-01-01'));
        $em->persist($share);

        $from = (new Basket())->setDate(new \DateTime('2099-08-07'))->setWeek(32)->setAmount(1);
        $to = (new Basket())->setDate(new \DateTime('2099-08-21'))->setWeek(34)->setAmount(1);
        $em->persist($from);
        $em->persist($to);

        // Entrega materializada de Mila en `from`: media cesta de verdura + ½ docena de huevos.
        $milaFrom = (new WeeklyBasket())
            ->setBasket($from)->setPartner($mila)->setWeeklyBasketStatus($picked)
            ->setBasketShare($sharedShare)->setAmount(1)->setDeliveryDate($from->getDate());
        $em->persist($milaFrom);
        $em->persist((new WeeklyBasketItem())->setWeeklyBasket($milaFrom)->setBasketComponent($veg)->setAmount('0.50'));
        $em->persist((new WeeklyBasketItem())->setWeeklyBasket($milaFrom)->setBasketComponent($eggs)->setAmount('0.50'));

        // `to` GENERADO (hay reparto esa semana): con el WB de la pareja basta para que el
        // applier materialice el destino de Mila. Sin esto, moveComponent solo dejaría el intent.
        $gladysTo = (new WeeklyBasket())
            ->setBasket($to)->setPartner($gladys)->setWeeklyBasketStatus($picked)
            ->setBasketShare($sharedShare)->setAmount(1)->setDeliveryDate($to->getDate());
        $em->persist($gladysTo);

        $em->flush();

        // ACTO: mover SOLO los huevos de Mila de `from` a `to`.
        /** @var DeliveryShiftApplier $applier */
        $applier = static::getContainer()->get(DeliveryShiftApplier::class);
        $applier->moveComponent($mila, $eggs, $from, $to, 'test');

        $em->clear();

        // La cesta (verdura) de Mila SIGUE en `from`; los huevos ya NO.
        $this->assertTrue($this->hasComponent($em, $mila->getId(), $from->getId(), self::ID_VEGETABLES), 'La verdura de la compartida no se mueve.');
        $this->assertFalse($this->hasComponent($em, $mila->getId(), $from->getId(), self::ID_EGGS), 'Los huevos deben salir del origen.');

        // Los huevos aterrizan en `to`; la verdura NO (solo se movió el componente huevos).
        $this->assertTrue($this->hasComponent($em, $mila->getId(), $to->getId(), self::ID_EGGS), 'Los huevos deben aterrizar en el destino.');
        $this->assertFalse($this->hasComponent($em, $mila->getId(), $to->getId(), self::ID_VEGETABLES), 'Solo se mueven los huevos, no la verdura.');

        // Queda el intent por componente (huevos), no uno de entrega entera.
        $shift = $em->getRepository(PartnerDeliveryShift::class)->findOneBy([
            'partner' => $mila->getId(), 'toBasket' => $to->getId(),
        ]);
        $this->assertNotNull($shift, 'Debe registrarse el cambio puntual.');
        $this->assertSame(self::ID_EGGS, $shift->getComponent()?->getId(), 'El intent es del componente huevos.');
    }

    private function hasComponent(EntityManagerInterface $em, int $partnerId, int $basketId, int $componentId): bool
    {
        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basketId, 'partner' => $partnerId]);
        if ($wb === null) {
            return false;
        }

        return $em->getRepository(WeeklyBasketItem::class)
            ->findBy(['weeklyBasket' => $wb, 'basketComponent' => $componentId]) !== [];
    }
}
