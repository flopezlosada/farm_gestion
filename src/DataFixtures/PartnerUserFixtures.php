<?php

namespace App\DataFixtures;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\SharePayment;
use App\Entity\State;
use App\Entity\City;
use App\Entity\User;
use App\Entity\WeeklyBasketGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Carga un User con ROLE_PARTNER vinculado a un Partner activo, junto
 * con su PartnerBasketShare quincenal A. Pensado para tests funcionales
 * del panel del socix (no se mezcla con los 30 Partners aleatorios de
 * PartnerFixtures porque necesitamos datos controlados y deterministas).
 *
 * Credenciales: socix / socix.
 *
 * Se carga también en dev para probar el panel a mano sin tener que
 * crear el vínculo a través del flujo de magic-link cada vez.
 */
class PartnerUserFixtures extends Fixture implements DependentFixtureInterface
{
    public const PARTNER_SOCIX_REF = 'partner-socix-test';
    public const USER_SOCIX_USERNAME = 'socix';
    public const USER_SOCIX_PASSWORD = 'socix';
    public const USER_SOCIX_EMAIL = 'socix@csavega.local';

    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $partner = new Partner();
        $partner->setName('Socixa');
        $partner->setSurname('De Prueba');
        $partner->setDNI('00000000T');
        $partner->setCelular(600000000);
        $partner->setAddress('Camino Real, 1');
        $partner->setEmail(self::USER_SOCIX_EMAIL);
        $partner->setEatMeat(1);
        $partner->setHasFile(true);
        $partner->setIsActive(true);
        $partner->setInscriptionDate(new \DateTime('-1 year'));

        /** @var State $state */
        $state = $this->getReference(CatalogFixtures::REF_STATE_PREFIX . 'Madrid');
        $partner->setState($state);
        /** @var City $city */
        $city = $this->getReference(CatalogFixtures::REF_CITY_PREFIX . 'Madrid');
        $partner->setCity($city);
        /** @var WeeklyBasketGroup $group */
        $group = $this->getReference(CatalogFixtures::REF_WEEKLY_GROUP_PREFIX . 'Madrid');
        $partner->setWeeklyBasketGroup($group);
        /** @var SharePayment $payment */
        $payment = $this->getReference(CatalogFixtures::REF_SHARE_PAYMENT_PREFIX . 'Mensual');
        $partner->setSharePayment($payment);

        /** @var BasketShare $quincenal */
        $quincenal = $this->getReference(CatalogFixtures::REF_BASKET_PREFIX . 'Quincenal');

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($quincenal);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setVegetablesBasketAmount(1);
        $share->setMonthPrice($quincenal->getMonthPrice());
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('-6 months'));
        $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_A);

        $user = new User();
        $user->setUsername(self::USER_SOCIX_USERNAME);
        $user->setEmail(self::USER_SOCIX_EMAIL);
        $user->setPassword($this->hasher->hashPassword($user, self::USER_SOCIX_PASSWORD));
        $user->setPasswordSet(true);
        $user->setEnabled(true);
        $user->setRoles(['ROLE_PARTNER']);
        $user->setPartner($partner);

        $manager->persist($partner);
        $manager->persist($share);
        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::PARTNER_SOCIX_REF, $partner);
    }

    public function getDependencies(): array
    {
        return [
            CatalogFixtures::class,
            UserFixtures::class,
        ];
    }
}
