<?php

namespace App\DataFixtures;

use App\Entity\BasketShare;
use App\Entity\City;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Service\Partner\BasketPricing;
use App\Entity\SharePayment;
use App\Entity\State;
use App\Entity\WeeklyBasketGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;

/**
 * Genera ~30 socixs sintéticos con su PartnerBasketShare activo.
 *
 * El reparto entre tipos de cesta intenta parecerse al real: mayoría
 * semanales, algo de quincenal, mensual y solo-huevos.
 */
class PartnerFixtures extends Fixture implements DependentFixtureInterface
{
    private const PARTNER_COUNT = 30;

    private const CITY_NAMES = [
        'Madrid',
        'San Sebastián de los Reyes',
        'Algete',
        'Tres Cantos',
    ];

    /**
     * Muestra de grupos canónicos para los socixs sintéticos. No es
     * exhaustiva: cubre nodo Torremocha (varios grupos) y grupos sin PDF
     * conocido todavía para que los tests cubran ambos casos.
     */
    private const GROUP_NAMES = [
        'Torremocha',
        'La Cabrera',
        'Madrid',
    ];

    /**
     * Pesos relativos para repartir socixs entre tipos de cesta.
     * Aproxima el patrón real (más semanales que mensuales).
     */
    private const BASKET_WEIGHTS = [
        'Semanal'     => 50,
        'Quincenal'   => 20,
        'Mensual'     => 15,
        'Solo huevos' => 15,
    ];

    private const EGG_AMOUNT_NAMES = [
        'Media docena',
        'Docena',
        'Docena y media',
        'Dos docenas',
    ];

    private const EGG_PERIOD_NAMES = ['Semanal', 'Quincenal', 'Mensual'];

    public function __construct(private readonly BasketPricing $basketPricing)
    {
    }

    /**
     * Carga los socixs sintéticos junto con su cesta activa.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('es_ES');

        for ($i = 0; $i < self::PARTNER_COUNT; $i++) {
            $partner = $this->createPartner($faker);
            $manager->persist($partner);

            $basketShare = $this->pickBasketShare($faker);
            $partnerBasketShare = $this->createPartnerBasketShare($faker, $partner, $basketShare);
            $manager->persist($partnerBasketShare);
        }

        $manager->flush();
    }

    /**
     * Declara las dependencias de fixtures: los catálogos deben cargarse antes.
     *
     * @return array<class-string>
     */
    public function getDependencies(): array
    {
        return [CatalogFixtures::class];
    }

    /**
     * Construye un Partner con datos sintéticos coherentes.
     *
     * @param Generator $faker
     *
     * @return Partner
     */
    private function createPartner(Generator $faker): Partner
    {
        $partner = new Partner();
        $partner->setName($faker->firstName);
        $partner->setSurname($faker->lastName . ' ' . $faker->lastName);
        $partner->setDNI($faker->dni);
        $partner->setCelular($faker->unique()->numerify('6########'));
        $partner->setAddress($faker->streetAddress);
        $partner->setEmail($faker->unique()->safeEmail);
        $partner->setEatMeat((int) $faker->boolean(70));
        $partner->setHasFile($faker->boolean(80));
        $partner->setIsActive(true);
        $partner->setInscriptionDate($faker->dateTimeBetween('-3 years', '-1 month'));

        $cityName = $faker->randomElement(self::CITY_NAMES);
        /** @var City $city */
        $city = $this->getReference(CatalogFixtures::REF_CITY_PREFIX . $cityName, City::class);
        $partner->setCity($city);

        /** @var State $state */
        $state = $this->getReference(CatalogFixtures::REF_STATE_PREFIX . 'Madrid', State::class);
        $partner->setState($state);

        $groupName = $faker->randomElement(self::GROUP_NAMES);
        /** @var WeeklyBasketGroup $group */
        $group = $this->getReference(CatalogFixtures::REF_WEEKLY_GROUP_PREFIX . $groupName, WeeklyBasketGroup::class);
        $partner->setWeeklyBasketGroup($group);

        $paymentName = $faker->randomElement(['Anual', 'Mensual']);
        /** @var SharePayment $payment */
        $payment = $this->getReference(CatalogFixtures::REF_SHARE_PAYMENT_PREFIX . $paymentName, SharePayment::class);
        $partner->setSharePayment($payment);

        return $partner;
    }

    /**
     * Crea un PartnerBasketShare activo coherente con el tipo de cesta.
     *
     * Reglas:
     * - "Solo huevos": obligatoriamente egg_amount + egg_period; precio cesta verduras 0.
     * - Otras: cesta de verduras con su precio; huevos opcionales (50%).
     * - "Mensual": además, day_month_order obligatorio (1, 2, 3 o -1 = última
     *   semana del mes; ver MonthlyOperativeOrderResolver::ordersServedBy).
     *
     * @param Generator    $faker
     * @param Partner      $partner
     * @param BasketShare  $basketShare
     *
     * @return PartnerBasketShare
     */
    private function createPartnerBasketShare(
        Generator $faker,
        Partner $partner,
        BasketShare $basketShare
    ): PartnerBasketShare {
        $partnerBasketShare = new PartnerBasketShare();
        $partnerBasketShare->setPartner($partner);
        $partnerBasketShare->setBasketShare($basketShare);
        $partnerBasketShare->setIsActive(true);
        $partnerBasketShare->setAmount(1);
        $partnerBasketShare->setVegetablesBasketAmount(1);
        $partnerBasketShare->setStartDate($faker->dateTimeBetween('-2 years', '-1 month'));

        $isOnlyEggs = $basketShare->getName() === 'Solo huevos';
        $hasEggs = $isOnlyEggs || $faker->boolean(50);

        if ($hasEggs) {
            $eggAmountName = $faker->randomElement(self::EGG_AMOUNT_NAMES);
            /** @var EggAmount $eggAmount */
            $eggAmount = $this->getReference(CatalogFixtures::REF_EGG_AMOUNT_PREFIX . $eggAmountName, EggAmount::class);
            $partnerBasketShare->setEggAmount($eggAmount);

            $eggPeriodName = $faker->randomElement(self::EGG_PERIOD_NAMES);
            /** @var EggPeriod $eggPeriod */
            $eggPeriod = $this->getReference(CatalogFixtures::REF_EGG_PERIOD_PREFIX . $eggPeriodName, EggPeriod::class);
            $partnerBasketShare->setEggPeriod($eggPeriod);
        }

        // Cuota con la fórmula real (verdura × cestas + huevos por frecuencia × docenas).
        $this->basketPricing->applyTo($partnerBasketShare);

        if ($basketShare->getName() === 'Mensual') {
            // -1 en vez de 4: la última semana del mes es una opción de primera
            // clase (índice negativo), no un ordinal fijo. Así las fixtures
            // ejercitan el emparejamiento «última» que arregla los meses de 5.
            $partnerBasketShare->setDayMonthOrder($faker->randomElement([1, 2, 3, -1]));
        }

        return $partnerBasketShare;
    }

    /**
     * Selecciona un BasketShare ponderado por las probabilidades configuradas.
     *
     * @param Generator $faker
     *
     * @return BasketShare
     */
    private function pickBasketShare(Generator $faker): BasketShare
    {
        $total = array_sum(self::BASKET_WEIGHTS);
        $roll = $faker->numberBetween(1, $total);
        $cursor = 0;

        foreach (self::BASKET_WEIGHTS as $name => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                /** @var BasketShare $basket */
                $basket = $this->getReference(CatalogFixtures::REF_BASKET_PREFIX . $name, BasketShare::class);

                return $basket;
            }
        }

        /** @var BasketShare $basket */
        $basket = $this->getReference(CatalogFixtures::REF_BASKET_PREFIX . 'Semanal', BasketShare::class);

        return $basket;
    }
}
