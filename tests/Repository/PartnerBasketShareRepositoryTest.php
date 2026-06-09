<?php

namespace App\Tests\Repository;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\PartnerBasketShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test del finder de candidatos semanal (findBasketPartnersByTypeAndCity).
 * Autocontenido: crea su propio Basket y socios, no depende del estado de
 * db_test. Cubre la ventana de vigencia de la PBS frente a la fecha del Basket,
 * que es donde se coló un bug: el finder semanal no filtraba por end_date, así
 * que un socio con baja programada a futuro (is_active aún 1) seguía apareciendo
 * en listados adelantados POSTERIORES a su baja.
 */
class PartnerBasketShareRepositoryTest extends KernelTestCase
{
    private const SHARE_WEEKLY = 1;

    public function testWeeklyFinderRespetaLaVentanaDeVigenciaFrenteAlBasket(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        // Basket con fecha imposible de colisionar con las fixtures.
        $basketDate = new \DateTime('2099-06-26');
        $basket = new Basket();
        $basket->setDate($basketDate);
        $basket->setWeek(26);
        $basket->setAmount(1);
        $em->persist($basket);

        $weekly = $em->getRepository(BasketShare::class)->find(self::SHARE_WEEKLY);
        $this->assertNotNull($weekly, 'El catálogo debe tener la cesta semanal (id 1).');

        // Tres socios semanales que sólo difieren en su end_date frente al Basket.
        $ended = $this->makeWeeklyShare($em, $weekly, 'RepoTest Baja',   new \DateTime('2099-06-19')); // antes  → fuera
        $border = $this->makeWeeklyShare($em, $weekly, 'RepoTest Borde', new \DateTime('2099-06-26')); // mismo día → dentro
        $active = $this->makeWeeklyShare($em, $weekly, 'RepoTest Activa', null);                        // sin fin  → dentro
        $em->flush();

        $result = $em->getRepository(PartnerBasketShare::class)
            ->findBasketPartnersByTypeAndCity(self::SHARE_WEEKLY, 1, $basket);
        /** @var PartnerBasketShareRepository $repo (firma documentada) */
        $ids = array_map(static fn (PartnerBasketShare $s): int => $s->getId(), $result);

        $this->assertNotContains($ended->getId(), $ids, 'Un socio con baja ANTES de la fecha del Basket no debe ser candidato.');
        $this->assertContains($border->getId(), $ids, 'Un socio cuya baja cae EL MISMO día del Basket sí recibe (última entrega).');
        $this->assertContains($active->getId(), $ids, 'Un socio sin baja debe seguir siendo candidato.');
    }

    private function makeWeeklyShare(
        EntityManagerInterface $em,
        BasketShare $weekly,
        string $name,
        ?\DateTimeInterface $endDate,
    ): PartnerBasketShare {
        $partner = new Partner();
        $partner->setName($name);
        $em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($weekly);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setEndDate($endDate);
        $em->persist($share);

        return $share;
    }
}
