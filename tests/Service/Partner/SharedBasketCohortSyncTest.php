<?php

namespace App\Tests\Service\Partner;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Partner\SharedBasketCohortSync;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de {@see SharedBasketCohortSync}: al cambiar el turno/orden de un
 * socio con cesta compartida, su par debe quedar en el MISMO turno y verse
 * reconciliado. Regresión del bug 2026-06-30: cambiar de quincena a uno dejaba
 * al otro en la quincena original y la cesta compartida se partía en dos.
 */
class SharedBasketCohortSyncTest extends TestCase
{
    private PartnerBasketShareRepository&MockObject $shareRepository;
    private WeeklyBasketGenerator&MockObject $generator;
    private EntityManagerInterface&MockObject $em;
    private SharedBasketCohortSync $sync;

    protected function setUp(): void
    {
        $this->shareRepository = $this->createMock(PartnerBasketShareRepository::class);
        $this->generator = $this->createMock(WeeklyBasketGenerator::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sync = new SharedBasketCohortSync($this->shareRepository, $this->generator, $this->em);
    }

    public function testSinParejaNoHaceNada(): void
    {
        $partner = $this->partner(1, mate: null);
        $source = $this->share($partner, BasketShare::IDS_SHARED[1], 'A', null);

        $this->generator->expects($this->never())->method('reconcilePartnerFrom');
        $this->em->expects($this->never())->method('flush');

        $this->assertNull($this->sync->syncFrom($source, new \DateTime('2026-07-01')));
    }

    public function testModalidadNoCompartidaNoSincroniza(): void
    {
        $mate = $this->partner(2, mate: null);
        $partner = $this->partner(1, mate: $mate);
        // Quincenal NO compartida (2): aunque tenga par, no es una cesta compartida.
        $source = $this->share($partner, BasketShare::ID_BIWEEKLY, 'A', null);

        $this->shareRepository->expects($this->never())->method('findLatestActiveForPartner');
        $this->generator->expects($this->never())->method('reconcilePartnerFrom');

        $this->assertNull($this->sync->syncFrom($source, new \DateTime('2026-07-01')));
    }

    public function testPropagaTurnoYReconciliaAlPar(): void
    {
        $mate = $this->partner(2, mate: null);
        $partner = $this->partner(1, mate: $mate);
        $mate->setSharePartner($partner);

        $source = $this->share($partner, BasketShare::IDS_SHARED[1], 'A', null); // quincenal compartida (6), turno A
        $mateShare = $this->share($mate, BasketShare::IDS_SHARED[1], 'B', null);  // par desincronizado en B

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($mateShare);

        $from = new \DateTime('2026-07-01');
        $this->em->expects($this->once())->method('flush');
        $this->generator->expects($this->once())->method('reconcilePartnerFrom')->with($mate, $from);

        $this->assertSame($mate, $this->sync->syncFrom($source, $from));
        $this->assertSame('A', $mateShare->getDeliveryGroup(), 'El par debe quedar en el turno del socio.');
    }

    public function testPropagaTambienElOrdenMensual(): void
    {
        // Mensual compartida (7): lo que define "cuándo recogen" es day_month_order,
        // no el turno A/B. Debe propagarse igual al par.
        $mate = $this->partner(2, mate: null);
        $partner = $this->partner(1, mate: $mate);
        $mate->setSharePartner($partner);

        $source = $this->share($partner, BasketShare::IDS_SHARED[2], null, 2);   // mensual compartida, 2º viernes
        $mateShare = $this->share($mate, BasketShare::IDS_SHARED[2], null, 1);    // par en 1º viernes

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($mateShare);
        $this->em->expects($this->once())->method('flush');
        $this->generator->expects($this->once())->method('reconcilePartnerFrom');

        $this->assertSame($mate, $this->sync->syncFrom($source, new \DateTime('2026-07-01')));
        $this->assertSame(2, $mateShare->getDayMonthOrder(), 'El par debe heredar el orden mensual del socio.');
    }

    public function testYaSincronizadosNoReconcilia(): void
    {
        $mate = $this->partner(2, mate: null);
        $partner = $this->partner(1, mate: $mate);
        $mate->setSharePartner($partner);

        $source = $this->share($partner, BasketShare::IDS_SHARED[1], 'B', null);
        $mateShare = $this->share($mate, BasketShare::IDS_SHARED[1], 'B', null);

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($mateShare);

        $this->generator->expects($this->never())->method('reconcilePartnerFrom');
        $this->em->expects($this->never())->method('flush');

        $this->assertNull($this->sync->syncFrom($source, new \DateTime('2026-07-01')));
    }

    public function testParSinCestaActivaNoSincroniza(): void
    {
        $mate = $this->partner(2, mate: null);
        $partner = $this->partner(1, mate: $mate);
        $mate->setSharePartner($partner);

        $source = $this->share($partner, BasketShare::IDS_SHARED[1], 'A', null);
        $this->shareRepository->method('findLatestActiveForPartner')->willReturn(null);

        $this->generator->expects($this->never())->method('reconcilePartnerFrom');
        $this->em->expects($this->never())->method('flush');

        $this->assertNull($this->sync->syncFrom($source, new \DateTime('2026-07-01')));
    }

    private function partner(int $id, ?Partner $mate): Partner
    {
        $partner = new Partner();
        $ref = new \ReflectionProperty(Partner::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($partner, $id);
        if ($mate !== null) {
            $partner->setSharePartner($mate);
        }

        return $partner;
    }

    private function share(Partner $partner, int $basketShareId, ?string $group, ?int $order): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        $ref = new \ReflectionProperty(BasketShare::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basketShare, $basketShareId);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basketShare);
        $share->setAmount(1);
        $share->setDeliveryGroup($group);
        $share->setDayMonthOrder($order);

        return $share;
    }
}
