<?php

namespace App\Tests\Repository;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test del finder MATERIALIZADO que alimenta el recordatorio de recogida
 * ({@see WeeklyBasketRepository::findPickedForBasketByShares}). Es la corrección
 * de la deuda del comando app:send-pickup-reminders: antes resolvía a quién
 * avisar con finders legacy por modalidad+cohorte, que ignoraban skips. Ahora
 * lee las WeeklyBasket materializadas, así que:
 *
 *   - quien marcó "no recojo" (status 2) NO sale,
 *   - lxs semanales (modalidad 1) NO salen (no se filtran como quincenal/mensual),
 *   - quincenales (2) y mensuales (3) que se recogen (status 1) SÍ salen.
 *
 * Autocontenido: Basket con fecha imposible (2099) para no colisionar con las
 * fixtures de db_test.
 */
class WeeklyBasketRepositoryTest extends KernelTestCase
{
    private const STATUS_PICKS = 1;     // "Recoge"
    private const STATUS_SKIPS = 2;     // "No la recoge"

    public function testFinderMaterializadoRespetaSkipsYModalidad(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $basket = (new Basket())
            ->setDate(new \DateTime('2099-07-03'))
            ->setWeek(27)
            ->setAmount(1);
        $em->persist($basket);

        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKS);
        $skips = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_SKIPS);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);
        $monthly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_MONTHLY);
        $weekly = $em->getRepository(BasketShare::class)->find(1);
        $this->assertNotNull($picks);
        $this->assertNotNull($skips);
        $this->assertNotNull($biweekly);
        $this->assertNotNull($monthly);
        $this->assertNotNull($weekly);

        $quincenalRecoge = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, 'WBRepo Quincenal recoge');
        $mensualRecoge   = $this->makeWeeklyBasket($em, $basket, $monthly,  $picks, 'WBRepo Mensual recoge');
        $quincenalSkip   = $this->makeWeeklyBasket($em, $basket, $biweekly, $skips, 'WBRepo Quincenal NO recoge');
        $semanalRecoge   = $this->makeWeeklyBasket($em, $basket, $weekly,   $picks, 'WBRepo Semanal recoge');
        $em->flush();

        /** @var WeeklyBasketRepository $repo */
        $repo = $em->getRepository(WeeklyBasket::class);
        $result = $repo->findPickedForBasketByShares($basket, [BasketShare::ID_BIWEEKLY, BasketShare::ID_MONTHLY]);
        $ids = array_map(static fn (WeeklyBasket $wb): int => $wb->getId(), $result);

        $this->assertContains($quincenalRecoge->getId(), $ids, 'Quincenal que recoge debe recibir aviso.');
        $this->assertContains($mensualRecoge->getId(), $ids, 'Mensual que recoge debe recibir aviso.');
        $this->assertNotContains($quincenalSkip->getId(), $ids, 'Quien marcó "no recojo" (status 2) NO debe recibir aviso.');
        $this->assertNotContains($semanalRecoge->getId(), $ids, 'Lxs semanales no entran en el recordatorio.');
    }

    public function testFinderConModalidadesVaciasDevuelveNada(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $basket = (new Basket())->setDate(new \DateTime('2099-07-10'))->setWeek(28)->setAmount(1);
        $em->persist($basket);
        $em->flush();

        /** @var WeeklyBasketRepository $repo */
        $repo = $em->getRepository(WeeklyBasket::class);
        $this->assertSame([], $repo->findPickedForBasketByShares($basket, []));
    }

    private function makeWeeklyBasket(
        EntityManagerInterface $em,
        Basket $basket,
        BasketShare $share,
        WeeklyBasketStatus $status,
        string $partnerName,
    ): WeeklyBasket {
        $partner = (new Partner())->setName($partnerName);
        $em->persist($partner);

        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setBasket($basket)
            ->setBasketShare($share)
            ->setWeeklyBasketStatus($status)
            ->setAmount(1);
        $em->persist($wb);

        return $wb;
    }
}
