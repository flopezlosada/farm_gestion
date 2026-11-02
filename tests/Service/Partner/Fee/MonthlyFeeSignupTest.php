<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\FeeConcept;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Service\Partner\Fee\FeeCharge;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La inscripción, contra db_test: va con el primer reparto de la primera cesta del
 * hogar. Una cesta que empieza después del último reparto de su mes la paga al mes
 * siguiente (antes se perdía: ese mes no tenía nada que cobrar).
 *
 * Repartos y socixs propios en 2093, que nadie más usa: db_test no deshace entre tests.
 */
class MonthlyFeeSignupTest extends KernelTestCase
{
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

    public function testLaInscripcionVaConElPrimerReparto(): void
    {
        foreach (['2093-01-23', '2093-01-30', '2093-02-06', '2093-02-13'] as $friday) {
            $this->basket($friday);
        }
        $late = $this->partner('ZZTEST Tardía', '2093-01-31');
        $early = $this->partner('ZZTEST Temprana', '2093-01-20');
        $calculator = static::getContainer()->get(MonthlyFeeCalculator::class);

        $january = $calculator->forMonth(new \DateTimeImmutable('2093-01-01'));
        $this->assertNull($january->forPartner($late), 'En enero no tiene ningún reparto: no paga nada.');
        $this->assertSame([FeeConcept::MembershipSignup], $this->concepts($january->forPartner($early)->getCharges()));

        $february = $calculator->forMonth(new \DateTimeImmutable('2093-02-01'));
        $this->assertSame([FeeConcept::MembershipSignup], $this->concepts($february->forPartner($late)->getCharges()), 'Su primer reparto es en febrero: ahí va la inscripción.');
        $this->assertSame([], $this->concepts($february->forPartner($early)->getCharges()), 'La pagó en enero.');
    }

    /**
     * @param list<FeeCharge> $charges
     *
     * @return list<FeeConcept>
     */
    private function concepts(array $charges): array
    {
        return array_map(static fn (FeeCharge $c): FeeConcept => $c->concept, $charges);
    }

    private function partner(string $name, string $start): Partner
    {
        $em = $this->em();
        $partner = (new Partner())->setname($name);
        $em->persist($partner);
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find(BasketShare::ID_WEEKLY));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setStartDate(new \DateTime($start));
        $em->persist($share);
        $em->flush();
        array_push($this->created, $partner, $share);

        return $partner;
    }

    private function basket(string $date): void
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($date));
        $basket->setWeek((int) (new \DateTime($date))->format('W'));
        $basket->setAmount(1);
        $this->em()->persist($basket);
        $this->em()->flush();
        $this->created[] = $basket;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
