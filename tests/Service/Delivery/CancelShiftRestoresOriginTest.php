<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerPause;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\DeliveryShiftApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deshacer un cambio de día cuando el listado del ORIGEN ya se generó sin la cesta.
 *
 * El socix mueve su cesta a otra semana; el listado del origen se genera (o congela) con el
 * cambio vigente, así que no sale en él —correcto—. Si después deshace el cambio, la cesta
 * vuelve al calendario, pero el generador ya no pasa por esa semana: si el applier no crea
 * la entrega del origen, el calendario la enseña y el listado impreso no la lleva.
 */
class CancelShiftRestoresOriginTest extends KernelTestCase
{
    private const STATUS_PICKED = 1;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Camino real: el socix devuelve la cesta a su día desde el calendario (move de vuelta al
     * origen = cancelar el cambio). La entrega vuelve con lo que llevaba en el destino.
     */
    public function testVolverAlOrigenGeneradoSinLaCestaLaRecreaConLoQueLlevaba(): void
    {
        [$from, $to] = $this->weeks('2099-10-23', '2099-10-30');
        $partner = $this->partnerWithShare('2020-01-01');
        $this->otherDelivery($from);
        $this->otherDelivery($to);
        $this->em->persist(new PartnerDeliveryShift($partner, $from, $to));
        $this->delivery($partner, $to, '0.50');
        $this->em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)->move($partner, $to, $from, 'test:cancel-origin');
        $this->em->clear();

        $this->assertSame(['1' => '0.50'], $this->linesOf($partner, $from), 'La cesta vuelve al listado del origen con lo que llevaba (media cesta quitada a mano).');
        $this->assertNull($this->deliveryOf($partner, $to), 'Y deja el destino.');
        $this->assertSame(self::STATUS_PICKED, $this->deliveryOf($partner, $from)?->getWeeklyBasketStatus()?->getId());
        $this->assertSame([], $this->em->getRepository(PartnerDeliveryShift::class)->findBy(['partner' => $partner->getId()]));
    }

    /**
     * Destino aún sin generar (la cesta solo estaba prevista allí): no hay composición que
     * traer de vuelta, así que el origen se compone de su patrón.
     */
    public function testCancelarConDestinoSinGenerarComponeElOrigenDelPatron(): void
    {
        [$from, $to] = $this->weeks('2099-11-06', '2099-11-13');
        $partner = $this->partnerWithShare('2020-01-01');
        $this->otherDelivery($from);
        $shift = new PartnerDeliveryShift($partner, $from, $to);
        $this->em->persist($shift);
        $this->em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)->cancel($shift, 'test:cancel-origin');
        $this->em->clear();

        $this->assertSame(['1' => '1.00'], $this->linesOf($partner, $from), 'Una cesta semanal entera, del patrón.');
    }

    /**
     * Semana origen sin generar: basta borrar el cambio, el generador la sacará del patrón.
     * Crear aquí una sola entrega dejaría el listado a medias y el generador lo daría por hecho.
     */
    public function testCancelarConOrigenSinGenerarNoCreaNada(): void
    {
        [$from, $to] = $this->weeks('2099-11-20', '2099-11-27');
        $partner = $this->partnerWithShare('2020-01-01');
        $shift = new PartnerDeliveryShift($partner, $from, $to);
        $this->em->persist($shift);
        $this->em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)->cancel($shift, 'test:cancel-origin');
        $this->em->clear();

        $this->assertSame([], $this->em->getRepository(WeeklyBasket::class)->findBy(['basket' => $from->getId()]));
    }

    /**
     * Mismas condiciones de entrega que el generador: sin suscripción en vigor ese día o con
     * el socix en pausa, el origen no lleva su cesta aunque el cambio se deshaga.
     */
    public function testCancelarNoRecreaElOrigenSiNoTocabaEntrega(): void
    {
        [$from, $to] = $this->weeks('2099-12-04', '2099-12-11');
        $notYetStarted = $this->partnerWithShare('2099-12-05');
        $paused = $this->partnerWithShare('2020-01-01');
        $this->em->persist(new PartnerPause($paused, new \DateTimeImmutable('2099-12-01'), new \DateTimeImmutable('2099-12-08')));
        $this->otherDelivery($from);
        $shifts = [new PartnerDeliveryShift($notYetStarted, $from, $to), new PartnerDeliveryShift($paused, $from, $to)];
        array_walk($shifts, fn (PartnerDeliveryShift $s) => $this->em->persist($s));
        $this->em->flush();

        $applier = static::getContainer()->get(DeliveryShiftApplier::class);
        array_walk($shifts, fn (PartnerDeliveryShift $s) => $applier->cancel($s, 'test:cancel-origin'));
        $this->em->clear();

        $this->assertNull($this->deliveryOf($notYetStarted, $from), 'Sin suscripción en vigor ese día.');
        $this->assertNull($this->deliveryOf($paused, $from), 'En pausa ese día.');
    }

    /**
     * Dos semanas de reparto consecutivas.
     *
     * @return array{0: Basket, 1: Basket}
     */
    private function weeks(string $from, string $to): array
    {
        $baskets = array_map(
            fn (string $date) => (new Basket())->setDate(new \DateTime($date))->setWeek((int) (new \DateTime($date))->format('W'))->setAmount(1),
            [$from, $to],
        );
        array_walk($baskets, fn (Basket $b) => $this->em->persist($b));

        return $baskets;
    }

    /**
     * Socix sin nodo (se reparte en la fecha del ciclo) con una cesta semanal desde $startsOn.
     */
    private function partnerWithShare(string $startsOn): Partner
    {
        $partner = (new Partner())->setName('CancelOrigin ' . uniqid('', true));
        $partner->setIsActive(true);
        $this->em->persist($partner);

        // Sin encadenar: los setters heredados de esta entidad devuelven void.
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($this->em->getRepository(BasketShare::class)->find(BasketShare::ID_WEEKLY));
        $share->setStartDate(new \DateTime($startsOn));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setVegetablesBasketAmount(1);
        $this->em->persist($share);

        return $partner;
    }

    /**
     * Entrega de otro socix: marca la semana como ya generada.
     */
    private function otherDelivery(Basket $basket): void
    {
        $this->delivery((new Partner())->setName('CancelOriginOther ' . uniqid('', true)), $basket, '1.00');
    }

    /**
     * Entrega materializada de un socix con una línea de verdura.
     */
    private function delivery(Partner $partner, Basket $basket, string $vegetables): void
    {
        $this->em->persist($partner);
        $wb = (new WeeklyBasket())
            ->setBasket($basket)->setPartner($partner)
            ->setWeeklyBasketStatus($this->em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKED))
            ->setBasketShare($this->em->getRepository(BasketShare::class)->find(BasketShare::ID_WEEKLY))
            ->setAmount(1)->setDeliveryDate($basket->getDate());
        $this->em->persist($wb);
        $this->em->persist((new WeeklyBasketItem())
            ->setWeeklyBasket($wb)
            ->setBasketComponent($this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES))
            ->setAmount($vegetables));
    }

    private function deliveryOf(Partner $partner, Basket $basket): ?WeeklyBasket
    {
        return $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
    }

    /**
     * Líneas de la entrega como [id de componente => cantidad], o [] si no hay entrega.
     *
     * @return array<string, string>
     */
    private function linesOf(Partner $partner, Basket $basket): array
    {
        $wb = $this->deliveryOf($partner, $basket);
        if ($wb === null) {
            return [];
        }

        $lines = [];
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
            $lines[(string) $item->getBasketComponent()->getId()] = $item->getAmount();
        }

        return $lines;
    }
}
