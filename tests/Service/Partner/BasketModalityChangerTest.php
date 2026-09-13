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
        $old->setStartDate(new \DateTime('2026-01-01')); // vigente desde el pasado
        $new = $this->share($partner, 3);
        $effective = new \DateTime('2026-06-01');

        // Se cierra la suscripción ACTIVA en curso (no "la vigente en la víspera").
        $this->shareRepository->expects($this->once())
            ->method('findLatestActiveForPartner')
            ->with($this->identicalTo($partner))
            ->willReturn($old);

        $this->eventRecorder->expects($this->once())
            ->method('recordChange')
            ->with($old, $new, $effective, null)
            ->willReturn(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $outcome = $this->changer->applyChange($new, $effective);

        $this->assertSame($old, $outcome->closed);
        $this->assertNull($outcome->mate, 'Quien no comparte cesta no arrastra a nadie.');
        $this->assertSame('2026-05-31', $old->getEndDate()->format('Y-m-d'));
        $this->assertFalse($old->getIsActive());
        $this->assertSame('2026-06-01', $new->getStartDate()->format('Y-m-d'));
        $this->assertNull($new->getEndDate());
        $this->assertTrue($new->getIsActive());
    }

    public function testApplyChangeConFechaEfectivaFuturaMantieneLaAntiguaActiva(): void
    {
        // Cambio PROGRAMADO a futuro: la cesta vieja sigue vigente hasta su end_date
        // (víspera de la efectiva). Debe quedar is_active=1 —la cierra
        // finalizeExpiredShares al expirar—; desactivarla ya la sacaría de los finders
        // de reparto (exigen is_active=1) durante su cola aún válida y el socio se
        // caería del listado (bug ANTONIO MONTORO, socio 28, jul-2026).
        $partner = $this->partner(28);
        $old = $this->share($partner, 1);
        $old->setStartDate(new \DateTime('2018-05-25'));
        $old->setIsActive(true); // es la cesta activa en curso (findLatestActiveForPartner)
        $new = $this->share($partner, 1);
        $effective = new \DateTime('2099-08-01'); // muy futura: robusto a la fecha real

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($old);
        $this->eventRecorder->method('recordChange')
            ->willReturn(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $this->changer->applyChange($new, $effective);

        $this->assertSame('2099-07-31', $old->getEndDate()->format('Y-m-d'));
        $this->assertTrue(
            $old->getIsActive(),
            'Un cierre futuro deja la cesta vieja activa hasta que expire; la desactiva finalizeExpiredShares.',
        );
        $this->assertTrue($new->getIsActive());
    }

    public function testApplyChangeSobreCestaFuturaLaRetiraEnVezDeDuplicar(): void
    {
        // La cesta actual empieza el MISMO día efectivo (cambio sobre una cesta que
        // aún no ha entrado en vigor): no hay histórico que partir, se retira la vieja
        // para no dejar dos activas (regresión Ana Villa 2026-06-30).
        $partner = $this->partner(7);
        $old = $this->share($partner, 6);
        $old->setStartDate(new \DateTime('2026-07-01'));
        $new = $this->share($partner, 6);
        $effective = new \DateTime('2026-07-01');

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($old);
        $this->eventRecorder->method('recordChange')
            ->willReturn(new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $this->em->expects($this->once())->method('remove')->with($this->identicalTo($old));

        $this->changer->applyChange($new, $effective);

        $this->assertTrue($new->getIsActive());
        $this->assertSame('2026-07-01', $new->getStartDate()->format('Y-m-d'));
        // No se le pone end_date < start_date ni transitoriamente: se retira, no se cierra.
        $this->assertNull($old->getEndDate(), 'Una cesta futura se retira, no se cierra con fecha invertida.');
    }

    public function testApplyChangeFlusheaAntesDeEmitirElEvento(): void
    {
        $partner = $this->partner(7);
        $old = $this->share($partner, 2);
        $new = $this->share($partner, 3);
        $effective = new \DateTime('2026-06-01');

        $this->shareRepository->method('findLatestActiveForPartner')->willReturn($old);

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
        $this->shareRepository->method('findLatestActiveForPartner')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->changer->applyChange($new, new \DateTime('2026-06-01'));
    }

    public function testApplyChangeEnCestaCompartidaArrastraAlOtroHogarYLoDevuelve(): void
    {
        // Caso real (13-sep-2026): una pareja quincenal compartida pasa a mensual
        // compartida. Aplicarlo en la ficha de una debe cambiar las dos cestas —la ley
        // L22 las obliga a ir iguales— y quien llama tiene que ENTERARSE de a quién
        // arrastró: es lo que necesita para reconciliar sus entregas y avisarle.
        [$one, $mate] = $this->sharingPair(30, 31);

        $old = $this->share($one, BasketShare::ID_BIWEEKLY_SHARED);
        $old->setStartDate(new \DateTime('2020-04-27'));
        $old->setDeliveryGroup('B');

        $mateOld = $this->share($mate, BasketShare::ID_BIWEEKLY_SHARED);
        $mateOld->setStartDate(new \DateTime('2023-01-12'));
        $mateOld->setDeliveryGroup('B');
        $mateOld->setMonthPrice('42.00');
        $mateOld->setEggMonthPrice('7.00');

        $new = $this->share($one, BasketShare::ID_MONTHLY_SHARED);
        $new->setDayMonthOrder(3);
        $new->setMonthPrice('55.00');
        $effective = new \DateTime('2026-10-01');

        $this->shareRepository->method('findLatestActiveForPartner')
            ->willReturnCallback(static fn (Partner $p): PartnerBasketShare => $p === $mate ? $mateOld : $old);
        $this->eventRecorder->method('recordChange')
            ->willReturn(new PartnerEvent($one, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $outcome = $this->changer->applyChange($new, $effective);

        $this->assertNotNull($outcome->mate, 'El cambio arrastra al otro hogar y debe devolverlo.');
        $this->assertSame($mate, $outcome->matePartner());

        // De la pareja viaja lo que define a la pareja...
        $this->assertSame(BasketShare::ID_MONTHLY_SHARED, $outcome->mate->getBasketShare()?->getId());
        $this->assertSame(3, $outcome->mate->getDayMonthOrder());
        $this->assertSame('2026-10-01', $outcome->mate->getStartDate()->format('Y-m-d'));

        // ...y lo de su casa se queda en su casa: compartir cesta no es compartir cuota.
        $this->assertSame('42.00', $outcome->mate->getMonthPrice());
        $this->assertSame('7.00', $outcome->mate->getEggMonthPrice());

        // Su histórico también se parte: la suya vieja se cierra en la víspera.
        $this->assertSame('2026-09-30', $mateOld->getEndDate()->format('Y-m-d'));
    }

    public function testApplyChangeNoArrastraSiLaModalidadNuevaNoEsCompartida(): void
    {
        // Dejar de compartir es otra conversación —con quién sigue, o si ya no comparte—
        // y nadie debería acabar en una cesta entera por la puerta de atrás.
        [$one, $mate] = $this->sharingPair(30, 31);

        $old = $this->share($one, BasketShare::ID_BIWEEKLY_SHARED);
        $old->setStartDate(new \DateTime('2020-04-27'));
        $mateOld = $this->share($mate, BasketShare::ID_BIWEEKLY_SHARED);
        $mateOld->setStartDate(new \DateTime('2023-01-12'));

        $new = $this->share($one, BasketShare::ID_WEEKLY);
        $effective = new \DateTime('2026-10-01');

        $this->shareRepository->method('findLatestActiveForPartner')
            ->willReturnCallback(static fn (Partner $p): PartnerBasketShare => $p === $mate ? $mateOld : $old);
        $this->eventRecorder->method('recordChange')
            ->willReturn(new PartnerEvent($one, PartnerEvent::TYPE_BASKET_CHANGE, $effective));

        $outcome = $this->changer->applyChange($new, $effective);

        $this->assertNull($outcome->mate);
        $this->assertNull($mateOld->getEndDate(), 'Al otro hogar no se le toca el histórico.');
    }

    /**
     * Dos socixs que comparten cesta, emparejadxs en los dos sentidos como en BBDD.
     *
     * @param int $oneId  Id del primer hogar.
     * @param int $mateId Id del segundo.
     *
     * @return array{0: Partner, 1: Partner} Los dos socixs.
     */
    private function sharingPair(int $oneId, int $mateId): array
    {
        $one = $this->partner($oneId);
        $mate = $this->partner($mateId);
        $one->setSharePartner($mate);
        $mate->setSharePartner($one);

        return [$one, $mate];
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
        // Columnas NOT NULL en BBDD, y sus setters no aceptan null: una cesta de mentira
        // sin ellas revienta en cuanto algo la copia (la cascada al otro hogar lo hace).
        $share->setVegetablesBasketAmount(1);
        $share->setEggMonthPrice('0.00');

        return $share;
    }
}
