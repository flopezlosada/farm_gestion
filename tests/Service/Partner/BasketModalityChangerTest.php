<?php

namespace App\Tests\Service\Partner;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Partner\BasketModalityChanger;
use App\Service\Partner\PartnerShareEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del BasketModalityChanger. Mockea EntityManager, repositorio de
 * PBS y el grabador de eventos para verificar la orquestación del cambio de
 * modalidad con histórico sin tocar BBDD.
 */
class BasketModalityChangerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PartnerBasketShareRepository&MockObject $shareRepository;
    private PartnerShareEventRecorder&MockObject $eventRecorder;
    private BasketModalityChanger $changer;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->shareRepository = $this->createMock(PartnerBasketShareRepository::class);
        $this->eventRecorder = $this->createMock(PartnerShareEventRecorder::class);
        $this->changer = new BasketModalityChanger($this->em, $this->shareRepository, $this->eventRecorder);
    }

    public function testApplyChangeCierraLaAntiguaEnLaVisperaYAbreLaNueva(): void
    {
        $partner = $this->partner(7);
        $old = $this->share($partner, 2);
        $new = $this->share($partner, 3);
        $effective = new \DateTime('2026-06-01');

        // La PBS vigente se busca en la VÍSPERA (31-may), no en la fecha efectiva.
        $this->shareRepository->expects($this->once())
            ->method('findActiveForPartner')
            ->with(
                $this->identicalTo($partner),
                $this->callback(fn (\DateTimeInterface $d) => $d->format('Y-m-d') === '2026-05-31'),
            )
            ->willReturn($old);

        $this->eventRecorder->expects($this->once())
            ->method('recordChange')
            ->with($old, $new, $effective, null)
            ->willReturn(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $returnedOld = $this->changer->applyChange($new, $effective);

        $this->assertSame($old, $returnedOld);
        $this->assertSame('2026-05-31', $old->getEndDate()->format('Y-m-d'));
        $this->assertFalse($old->getIsActive());
        $this->assertSame('2026-06-01', $new->getStartDate()->format('Y-m-d'));
        $this->assertNull($new->getEndDate());
        $this->assertTrue($new->getIsActive());
    }

    public function testApplyChangeFlusheaAntesDeEmitirElEvento(): void
    {
        $partner = $this->partner(7);
        $old = $this->share($partner, 2);
        $new = $this->share($partner, 3);
        $effective = new \DateTime('2026-06-01');

        $this->shareRepository->method('findActiveForPartner')->willReturn($old);

        $calls = [];
        $this->em->method('persist')->willReturnCallback(function () use (&$calls) { $calls[] = 'persist'; });
        $this->em->method('flush')->willReturnCallback(function () use (&$calls) { $calls[] = 'flush'; });
        $this->eventRecorder->method('recordChange')->willReturnCallback(function () use (&$calls, $partner, $effective) {
            $calls[] = 'recordChange';
            return new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_CHANGE, $effective);
        });

        $this->changer->applyChange($new, $effective);

        // El snapshot del evento usa getId(): int sobre la PBS nueva, así que
        // debe persistirse y flushearse ANTES de recordChange.
        $firstFlush = array_search('flush', $calls, true);
        $recordChange = array_search('recordChange', $calls, true);
        $this->assertNotFalse($firstFlush);
        $this->assertNotFalse($recordChange);
        $this->assertLessThan($recordChange, $firstFlush);
        $this->assertContains('persist', array_slice($calls, 0, $recordChange));
    }

    public function testApplyChangeSinCestaVigenteLanzaDomainException(): void
    {
        $partner = $this->partner(7);
        $new = $this->share($partner, 3);
        $this->shareRepository->method('findActiveForPartner')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->changer->applyChange($new, new \DateTime('2026-06-01'));
    }

    private function partner(int $id): Partner
    {
        $partner = new Partner();
        $ref = new \ReflectionProperty(Partner::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($partner, $id);

        return $partner;
    }

    private function share(Partner $partner, int $basketShareId): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        $ref = new \ReflectionProperty(BasketShare::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basketShare, $basketShareId);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basketShare);
        $share->setAmount(1);

        return $share;
    }
}
