<?php

namespace App\DataFixtures;

use App\Entity\BasketShare;
use App\Entity\City;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\SharePayment;
use App\Entity\State;
use App\Entity\WeeklyBasketGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds del catálogo de dominio: tipos de cesta, períodos/cantidades de huevos,
 * tipos de pago de cuota, grupos de reparto y geografía mínima.
 *
 * Estos valores no son aleatorios: representan las opciones reales que la
 * asociación maneja. Faker no aporta aquí, son seeds fijos.
 */
class CatalogFixtures extends Fixture
{
    public const REF_BASKET_PREFIX = 'basket_';
    public const REF_EGG_AMOUNT_PREFIX = 'egg_amount_';
    public const REF_EGG_PERIOD_PREFIX = 'egg_period_';
    public const REF_SHARE_PAYMENT_PREFIX = 'share_payment_';
    public const REF_WEEKLY_GROUP_PREFIX = 'weekly_group_';
    public const REF_CITY_PREFIX = 'city_';
    public const REF_STATE_PREFIX = 'state_';

    /**
     * Carga todos los catálogos y registra referencias para los fixtures dependientes.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $state = $this->createState($manager, 'Madrid');
        $this->addReference(self::REF_STATE_PREFIX . 'Madrid', $state);

        foreach (['Madrid', 'San Sebastián de los Reyes', 'Algete', 'Tres Cantos'] as $cityName) {
            $city = (new City())->setName($cityName);
            $city->setState($state);
            $manager->persist($city);
            $this->addReference(self::REF_CITY_PREFIX . $cityName, $city);
        }

        $baskets = [
            'Semanal'     => ['price' => '60.00', 'equivalence' => '1.00'],
            'Quincenal'   => ['price' => '30.00', 'equivalence' => '0.50'],
            'Mensual'     => ['price' => '15.00', 'equivalence' => '0.25'],
            'Solo huevos' => ['price' => '0.00',  'equivalence' => '0.00'],
        ];

        foreach ($baskets as $name => $data) {
            $basket = new BasketShare();
            $basket->setName($name);
            $basket->setMonthPrice($data['price']);
            $basket->setCompleteBasketEquivalence($data['equivalence']);
            $manager->persist($basket);
            $this->addReference(self::REF_BASKET_PREFIX . $name, $basket);
        }

        foreach (['Semanal', 'Quincenal'] as $eggPeriodName) {
            $eggPeriod = (new EggPeriod())->setName($eggPeriodName);
            $manager->persist($eggPeriod);
            $this->addReference(self::REF_EGG_PERIOD_PREFIX . $eggPeriodName, $eggPeriod);
        }

        $eggAmounts = [
            'Media docena'    => '8.00',
            'Docena'          => '14.00',
            'Docena y media'  => '20.00',
            'Dos docenas'     => '26.00',
        ];

        foreach ($eggAmounts as $name => $price) {
            $eggAmount = (new EggAmount())->setName($name)->setMonthPrice($price);
            $manager->persist($eggAmount);
            $this->addReference(self::REF_EGG_AMOUNT_PREFIX . $name, $eggAmount);
        }

        foreach (['Anual', 'Mensual'] as $payment) {
            $sharePayment = new SharePayment();
            $sharePayment->setName($payment);
            $manager->persist($sharePayment);
            $this->addReference(self::REF_SHARE_PAYMENT_PREFIX . $payment, $sharePayment);
        }

        $groups = [
            'Madrid'                     => '#e74c3c',
            'Vallecas'                   => '#3498db',
            'San Sebastián de los Reyes' => '#2ecc71',
        ];

        foreach ($groups as $name => $color) {
            $group = (new WeeklyBasketGroup())->setName($name)->setColor($color);
            $manager->persist($group);
            $this->addReference(self::REF_WEEKLY_GROUP_PREFIX . $name, $group);
        }

        $manager->flush();
    }

    /**
     * Crea y persiste una provincia/estado.
     *
     * @param ObjectManager $manager
     * @param string        $name
     *
     * @return State
     */
    private function createState(ObjectManager $manager, string $name): State
    {
        $state = (new State())->setName($name);
        $manager->persist($state);

        return $state;
    }
}
