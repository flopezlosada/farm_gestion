<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\FeeConcept;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Partner\Fee\FeeCharge;
use App\Service\Partner\Fee\FeeLine;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El primer mes de una quincenal o una mensual que empieza a medias, contra db_test y
 * con el patrón de reparto de verdad (turno A/B y orden mensual): se paga por las
 * cestas que recibe, no por las semanas que quedan. Las pruebas unitarias del cálculo
 * dan las cestas hechas; ésta comprueba que salen del reparto.
 *
 * Marzo de 2094, que nadie más usa (db_test no deshace entre tests): viernes 5, 12, 19
 * y 26. Todo empieza después del día 5. Socixs sin grupo, que reparten el viernes.
 */
class MonthlyFeeLateStartTest extends KernelTestCase
{
    private const FRIDAYS = ['2094-03-05', '2094-03-12', '2094-03-19', '2094-03-26'];

    /** @var list<object> Lo creado, para borrarlo al acabar. */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        foreach (array_reverse($this->created) as $row) {
            $em->remove($row);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testElPrimerMesSePagaPorLasCestasQueRecibe(): void
    {
        $baskets = array_map(fn (string $friday): Basket => $this->basket($friday), self::FRIDAYS);
        // El turno que recoge el 19: del 10 en adelante, el 12 y el 26 son del otro.
        $turn = static::getContainer()->get(BiweeklyCohortResolver::class)->cohortForBasket($baskets[2]);

        $biweekly = $this->partner('ZZTEST Quincenal', BasketShare::ID_BIWEEKLY, '2094-03-10', deliveryGroup: $turn);
        $monthly = $this->partner('ZZTEST Mensual del 19', BasketShare::ID_MONTHLY, '2094-03-10', dayMonthOrder: 3);
        $missed = $this->partner('ZZTEST Mensual de primeros', BasketShare::ID_MONTHLY, '2094-03-20', dayMonthOrder: 1);

        $march = static::getContainer()->get(MonthlyFeeCalculator::class)->forMonth(new \DateTimeImmutable('2094-03-01'));

        $this->assertSame([1, 2, true], $this->counts($march->forPartner($biweekly)->getLines()[0]), 'Su turno sólo le da el 19: una cesta de dos, no 3 de 4 semanas.');
        $this->assertSame([1, 1, true], $this->counts($march->forPartner($monthly)->getLines()[0]), 'Su mensual cae el 19: entera.');

        $missed = $march->forPartner($missed);
        $this->assertSame([0, 1, true], $this->counts($missed->getLines()[0]), 'Su mensual es la del día 5, antes de empezar: este mes no recibe nada.');
        $this->assertSame(0, $missed->getBasketsCents());
        $this->assertSame([FeeConcept::MembershipSignup], array_map(static fn (FeeCharge $c): FeeConcept => $c->concept, $missed->getCharges()), 'Pero es su primer mes: paga la inscripción.');
    }

    /** @return array{int, int, bool} Cestas (o semanas) que tuvo, las de un mes normal y si cuenta cestas. */
    private function counts(FeeLine $line): array
    {
        return [$line->weeks, $line->monthWeeks, $line->byDeliveries];
    }

    private function partner(string $name, int $basketShare, string $start, ?string $deliveryGroup = null, ?int $dayMonthOrder = null): Partner
    {
        $em = $this->em();
        $partner = (new Partner())->setname($name);
        $em->persist($partner);
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find($basketShare));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setStartDate(new \DateTime($start));
        $share->setDeliveryGroup($deliveryGroup);
        $share->setDayMonthOrder($dayMonthOrder);
        $em->persist($share);
        $em->flush();
        array_push($this->created, $partner, $share);

        return $partner;
    }

    private function basket(string $date): Basket
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($date));
        $basket->setWeek((int) (new \DateTime($date))->format('W'));
        $basket->setAmount(1);
        $this->em()->persist($basket);
        $this->em()->flush();
        $this->created[] = $basket;

        return $basket;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
