<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Service\Accounting\Remittance\BasketFeeExpectations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lo que suman las cestas de cada familia en un mes, contra la base: verdura más
 * huevos, a cargo de quien encabeza la familia o de quien paga por otra persona, y
 * sólo las cestas activas que tocan el mes. Las fechas van en 2098 para no cruzarse
 * con las de las fixtures.
 */
class BasketFeeExpectationsTest extends KernelTestCase
{
    /** @var list<object> */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = $this->em();
        foreach (array_reverse($this->created) as $entity) {
            $em->remove($em->getReference($entity::class, $entity->getId()));
        }
        $em->flush();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function partner(string $name, ?Partner $parent = null): Partner
    {
        $partner = (new Partner())->setname($name);
        $partner->setParent($parent);
        $this->em()->persist($partner);
        $this->created[] = $partner;

        return $partner;
    }

    private function share(Partner $partner, string $veg, string $eggs, string $start, ?string $end = null, bool $active = true, ?Partner $payer = null, ?string $transport = null): void
    {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($this->em()->getRepository(BasketShare::class)->find(1));
        $share->setIsActive($active);
        $share->setAmount(1);
        $share->setMonthPrice($veg);
        $share->setEggMonthPrice($eggs);
        $share->setTransportPrice($transport);
        $share->setStartDate(new \DateTime($start));
        $share->setEndDate($end !== null ? new \DateTime($end) : null);
        $share->setPayerPartner($payer);
        $this->em()->persist($share);
        $this->created[] = $share;
    }

    public function testSumaLasCestasDelMesPorFamiliaYPagador(): void
    {
        self::bootKernel();
        $ana = $this->partner('FeeTest Ana');
        $hija = $this->partner('FeeTest Hija', $ana);
        $bea = $this->partner('FeeTest Bea');
        $carlos = $this->partner('FeeTest Carlos');
        $dani = $this->partner('FeeTest Dani');

        $this->share($ana, '50.00', '15.00', '2098-01-01', transport: '5.00'); // verdura + huevos + transporte: 70
        $this->share($hija, '27.50', '0.00', '2098-03-20');                 // alta a mitad de mes, a la familia de Ana
        $this->share($carlos, '43.50', '0.00', '2098-01-01', payer: $bea);  // la paga Bea
        $this->share($dani, '60.00', '0.00', '2098-01-01', '2098-02-28');   // baja antes del mes
        $this->share($dani, '60.00', '0.00', '2098-04-01');                 // empieza después
        $this->share($bea, '70.00', '0.00', '2098-01-01', active: false);   // cesta cerrada
        $this->em()->flush();

        $fees = static::getContainer()->get(BasketFeeExpectations::class)->forMonth(new \DateTimeImmutable('2098-03-15'));

        $this->assertSame(9750, $fees[$ana->getId()]['cents'], 'El transporte también se cobra.');
        $this->assertSame($ana, $fees[$ana->getId()]['partner']);
        $this->assertArrayNotHasKey($hija->getId(), $fees, 'La cesta de la hija cuenta en la familia, no aparte.');
        $this->assertSame(4350, $fees[$bea->getId()]['cents'], 'Sólo lo que paga por Carlos: su propia cesta está cerrada.');
        $this->assertArrayNotHasKey($carlos->getId(), $fees);
        $this->assertArrayNotHasKey($dani->getId(), $fees);
    }
}
