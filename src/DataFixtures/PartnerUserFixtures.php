<?php

namespace App\DataFixtures;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\SharePayment;
use App\Entity\State;
use App\Entity\City;
use App\Entity\User;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
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
    public const PARTNER_PEER_REF = 'partner-peer-test';
    public const BASKET_NEXT_REF = 'basket-next-test';
    public const BASKET_FOLLOWING_REF = 'basket-following-test';
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
        $partner->setCelular('600000000');
        $partner->setAddress('Camino Real, 1');
        $partner->setEmail(self::USER_SOCIX_EMAIL);
        $partner->setEatMeat(1);
        $partner->setHasFile(true);
        $partner->setIsActive(true);
        $partner->setInscriptionDate(new \DateTime('-1 year'));

        /** @var State $state */
        $state = $this->getReference(CatalogFixtures::REF_STATE_PREFIX . 'Madrid', State::class);
        $partner->setState($state);
        /** @var City $city */
        $city = $this->getReference(CatalogFixtures::REF_CITY_PREFIX . 'Madrid', City::class);
        $partner->setCity($city);
        /** @var WeeklyBasketGroup $group */
        $group = $this->getReference(CatalogFixtures::REF_WEEKLY_GROUP_PREFIX . 'Madrid', WeeklyBasketGroup::class);
        $partner->setWeeklyBasketGroup($group);
        /** @var SharePayment $payment */
        $payment = $this->getReference(CatalogFixtures::REF_SHARE_PAYMENT_PREFIX . 'Mensual', SharePayment::class);
        $partner->setSharePayment($payment);

        /** @var BasketShare $quincenal */
        $quincenal = $this->getReference(CatalogFixtures::REF_BASKET_PREFIX . 'Quincenal', BasketShare::class);

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

        // Segundo partner quincenal del grupo B para tener masa con la que
        // probar la regla Balance en los flows POST. Sin User vinculado:
        // no necesita login.
        $peer = new Partner();
        $peer->setName('Compañerx');
        $peer->setSurname('De Prueba');
        $peer->setDNI('00000001R');
        $peer->setCelular('600000001');
        $peer->setAddress('Camino Real, 2');
        $peer->setEmail('peer@csavega.local');
        $peer->setEatMeat(1);
        $peer->setHasFile(true);
        $peer->setIsActive(true);
        $peer->setInscriptionDate(new \DateTime('-1 year'));
        $peer->setState($state);
        $peer->setCity($city);
        $peer->setWeeklyBasketGroup($group);
        $peer->setSharePayment($payment);

        $peerShare = new PartnerBasketShare();
        $peerShare->setPartner($peer);
        $peerShare->setBasketShare($quincenal);
        $peerShare->setIsActive(true);
        $peerShare->setAmount(1);
        $peerShare->setVegetablesBasketAmount(1);
        $peerShare->setMonthPrice($quincenal->getMonthPrice());
        $peerShare->setEggMonthPrice('0.00');
        $peerShare->setStartDate(new \DateTime('-6 months'));
        $peerShare->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_B);

        // Dos Baskets futuros consecutivos. nextFriday siempre a >= 7 días
        // para que el deadline (jueves anterior 23:59) caiga en el futuro
        // sin importar el día de la semana en que corra el test.
        $today = new \DateTimeImmutable('today');
        $nextFriday = $today->modify('next friday');
        if ($nextFriday->diff($today)->days < 7) {
            $nextFriday = $nextFriday->modify('+7 days');
        }
        $followingFriday = $nextFriday->modify('+7 days');

        $nextBasket = $this->createBasket($nextFriday);
        $followingBasket = $this->createBasket($followingFriday);

        /** @var WeeklyBasketStatus $statusPicked */
        $statusPicked = $this->getReference(CatalogFixtures::REF_WB_STATUS_PREFIX . 'Recoge', WeeklyBasketStatus::class);

        $wb = (new WeeklyBasket())
            ->setBasket($nextBasket)
            ->setPartner($partner)
            ->setBasketShare($quincenal)
            ->setWeeklyBasketGroup($group)
            ->setWeeklyBasketStatus($statusPicked)
            ->setAmount(1)
            ->setDeliveryDate($nextFriday);

        $peerWb = (new WeeklyBasket())
            ->setBasket($nextBasket)
            ->setPartner($peer)
            ->setBasketShare($quincenal)
            ->setWeeklyBasketGroup($group)
            ->setWeeklyBasketStatus($statusPicked)
            ->setAmount(1)
            ->setDeliveryDate($nextFriday);

        $manager->persist($partner);
        $manager->persist($share);
        $manager->persist($user);
        $manager->persist($peer);
        $manager->persist($peerShare);
        $manager->persist($nextBasket);
        $manager->persist($followingBasket);
        $manager->persist($wb);
        $manager->persist($peerWb);
        $manager->flush();

        $this->addReference(self::PARTNER_SOCIX_REF, $partner);
        $this->addReference(self::PARTNER_PEER_REF, $peer);
        $this->addReference(self::BASKET_NEXT_REF, $nextBasket);
        $this->addReference(self::BASKET_FOLLOWING_REF, $followingBasket);
    }

    private function createBasket(\DateTimeImmutable $date): Basket
    {
        $basket = new Basket();
        $basket->setDate(\DateTime::createFromImmutable($date));
        $basket->setWeek((int) $date->format('W'));
        $basket->setAmount(1);
        return $basket;
    }

    public function getDependencies(): array
    {
        return [
            CatalogFixtures::class,
            UserFixtures::class,
        ];
    }
}
