<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\WeeklyBasketSkipper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit del toggle de saltar/recoger una entrega. La lógica de estados (1 ↔ 2)
 * y de emisión de eventos se prueba aislada de la BBDD.
 */
class WeeklyBasketSkipperTest extends TestCase
{
    private function status(int $id): WeeklyBasketStatus
    {
        $status = $this->createMock(WeeklyBasketStatus::class);
        $status->method('getId')->willReturn($id);

        return $status;
    }

    /**
     * Construye un skipper cuyo repositorio de estados devuelve los mocks 1 y 2,
     * y captura por referencia las entidades persistidas (los PartnerEvent).
     *
     * @param array<int, object> $persisted
     */
    private function skipper(array &$persisted): WeeklyBasketSkipper
    {
        $statusRepo = $this->createMock(EntityRepository::class);
        $statusRepo->method('find')->willReturnCallback(fn (int $id) => $this->status($id));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($statusRepo);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return new WeeklyBasketSkipper($em);
    }

    public function testTogglesFromPicksToSkippedAndRecordsSkipEvent(): void
    {
        $persisted = [];
        $skipper = $this->skipper($persisted);

        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketStatus($this->status(WeeklyBasketSkipper::STATUS_PICKS));

        $result = $skipper->toggle($wb, 'gestor:1');

        $this->assertTrue($result, 'De "Recoge" pasa a saltada.');
        $this->assertSame(WeeklyBasketSkipper::STATUS_SKIPPED, $wb->getWeeklyBasketStatus()->getId());
        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(PartnerEvent::class, $persisted[0]);
        $this->assertSame(PartnerEvent::TYPE_BASKET_SKIP, $persisted[0]->getType());
        $this->assertSame('gestor:1', $persisted[0]->getActor());
    }

    public function testTogglesFromSkippedToPicksAndRecordsUnskipEvent(): void
    {
        $persisted = [];
        $skipper = $this->skipper($persisted);

        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketStatus($this->status(WeeklyBasketSkipper::STATUS_SKIPPED));

        $result = $skipper->toggle($wb, 'partner:42');

        $this->assertFalse($result, 'De saltada vuelve a "Recoge".');
        $this->assertSame(WeeklyBasketSkipper::STATUS_PICKS, $wb->getWeeklyBasketStatus()->getId());
        $this->assertCount(1, $persisted);
        $this->assertSame(PartnerEvent::TYPE_BASKET_UNSKIP, $persisted[0]->getType());
    }

    public function testSpecialStatusIsLeftUntouched(): void
    {
        $persisted = [];
        $skipper = $this->skipper($persisted);

        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketStatus($this->status(7));

        $result = $skipper->toggle($wb, 'gestor:1');

        $this->assertNull($result, 'Un estado distinto de 1/2 no se altera.');
        $this->assertSame(7, $wb->getWeeklyBasketStatus()->getId());
        $this->assertCount(0, $persisted, 'No se emite evento si no hay cambio.');
    }
}
