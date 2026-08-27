<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketItem;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\NodeEggRescheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Retirada y traslado en lote de los huevos de un reparto.
 *
 * El escenario se monta sobre Torremocha (nodo semanal de las fixtures) con la
 * semana de origen MATERIALIZADA, que es el caso que de verdad importa: cuando
 * administración descubre que no hay huevos, el listado de esa semana ya suele
 * estar en piedra. Se comprueban las tres cosas que pueden salir mal en un
 * lote: que toque a quien no debe, que pierda docenas por el camino, y que no
 * deje rastro de lo que hizo.
 */
class NodeEggReschedulerTest extends KernelTestCase
{
    /** Torremocha: semanal, reparte los viernes (NodeFixtures). */
    private const NODE_WEEKLY_ID = 1;

    /** Modalidad semanal (CatalogFixtures fuerza los ids del catálogo). */
    private const SHARE_WEEKLY = 1;

    private EntityManagerInterface $em;
    private NodeEggRescheduler $rescheduler;
    private Node $node;
    private WeeklyBasketGroup $group;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->rescheduler = static::getContainer()->get(NodeEggRescheduler::class);

        $node = $this->em->getRepository(Node::class)->find(self::NODE_WEEKLY_ID);
        self::assertInstanceOf(Node::class, $node, 'Las fixtures deben sembrar Torremocha.');
        $this->node = $node;

        $this->group = (new WeeklyBasketGroup())
            ->setName('TEST Huevos ' . uniqid('', true))
            ->setColor('#c89a3a')
            ->setNode($this->node);
        $this->em->persist($this->group);
    }

    /**
     * Lo primero que hay que no romper: el lote es "quien lleva huevos ese
     * día", no "todo el punto de recogida". Quien no los lleva ni se menciona.
     */
    public function testSoloListaAQuienLlevaHuevosEseDia(): void
    {
        $from = $this->basket('2099-09-04', 36);
        $conHuevos = $this->partnerDelivering($from, '1.00');
        $sinHuevos = $this->partnerDelivering($from, null);
        $this->em->flush();

        $affected = $this->rescheduler->affected($from, [$this->node]);
        $ids = array_map(static fn (array $r): int => $r['partner']->getId(), $affected);

        $this->assertContains($conHuevos->getId(), $ids);
        $this->assertNotContains($sinHuevos->getId(), $ids, 'Quien no lleva huevos ese día no entra en el lote.');
    }

    /**
     * Retirar sin recolocar: la entrega se queda sin huevos pero conserva la
     * verdura, y queda el intent que permite a L17 descontar esas docenas de la
     * conservación mensual. Sin ese rastro, el socio saldría en rojo.
     */
    public function testRetirarQuitaSoloLosHuevosYDejaRastro(): void
    {
        $from = $this->basket('2099-09-11', 37);
        $partner = $this->partnerDelivering($from, '1.00');
        $this->em->flush();

        $result = $this->rescheduler->apply($from, [$this->node], null, 'test');

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['moved']);
        $this->assertSame([], $result['skipped']);

        $this->em->clear();
        $this->assertSame(0.0, $this->materializedAmount($partner, $from, BasketComponent::ID_EGGS), 'Los huevos salen de la entrega.');
        $this->assertSame(1.0, $this->materializedAmount($partner, $from, BasketComponent::ID_VEGETABLES), 'La verdura no se toca.');

        $intents = $this->em->getRepository(PartnerDeliveryShift::class)
            ->findBy(['partner' => $partner->getId(), 'fromBasket' => $from->getId()]);
        $this->assertCount(1, $intents);
        $this->assertTrue($intents[0]->isSkip(), 'Sin destino: es una retirada.');
        $this->assertSame(BasketComponent::ID_EGGS, $intents[0]->getComponent()?->getId());
    }

    /**
     * Traslado: las docenas desaparecen del origen y reaparecen SUMADAS en el
     * destino. Ahí está el gesto que pedía administración — ese día se recogen
     * los huevos de las dos semanas.
     */
    public function testTrasladarSumaLasDocenasEnElRepartoDestino(): void
    {
        $from = $this->basket('2099-09-18', 38);
        $to = $this->basket('2099-09-25', 39);
        $partner = $this->partnerDelivering($from, '1.00');
        $this->em->flush();

        $result = $this->rescheduler->apply($from, [$this->node], $to, 'test');

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1.0, $result['dozens']);

        $this->em->clear();
        $this->assertSame(0.0, $this->materializedAmount($partner, $from, BasketComponent::ID_EGGS), 'El origen se queda sin huevos.');

        $eggs = $this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        $extra = $this->em->getRepository(PartnerBasketExtra::class)->findOneForPartnerBasketComponent(
            $this->em->getRepository(Partner::class)->find($partner->getId()),
            $this->em->getRepository(Basket::class)->find($to->getId()),
            $eggs,
        );
        $this->assertNotNull($extra, 'El destino tiene que recibir las docenas trasladadas.');
        $this->assertSame(1.0, (float) $extra->getAmount());
    }

    /**
     * El plazo: se puede tocar el reparto de hoy (la falta de huevos se
     * descubre esa mañana), pero no uno que ya pasó.
     */
    public function testNoSePuedeTocarUnRepartoPasado(): void
    {
        $from = $this->basket('2020-01-03', 1);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ese reparto ya pasó');
        $this->rescheduler->apply($from, [$this->node], null, 'test');
    }

    /**
     * Un traslado al mismo reparto no es un traslado. Se corta antes de tocar
     * nada, no a mitad del lote.
     */
    public function testElDestinoNoPuedeSerElOrigen(): void
    {
        $from = $this->basket('2099-10-02', 40);
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede ser el mismo');
        $this->rescheduler->apply($from, [$this->node], $from, 'test');
    }

    /**
     * Un socio que ya tiene un cambio de día activo esa semana se deja
     * intacto y se reporta: su semana la gobierna ese cambio, y pisarla desde
     * el lote dejaría dos estados a la vez.
     */
    public function testSaltaAQuienTieneUnCambioDeDiaEsaSemana(): void
    {
        $from = $this->basket('2099-10-09', 41);
        $otra = $this->basket('2099-10-16', 42);
        $partner = $this->partnerDelivering($from, '1.00');
        $this->em->persist(new PartnerDeliveryShift($partner, $from, $otra));
        $this->em->flush();

        $result = $this->rescheduler->apply($from, [$this->node], null, 'test');

        $this->assertSame(0, $result['removed']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('cambio de día', $result['skipped'][0]);
    }

    /**
     * Socio del grupo de test con entrega MATERIALIZADA en la semana dada.
     *
     * @param Basket      $basket Semana en la que se materializa la entrega.
     * @param string|null $dozens Docenas de huevos, o null para no llevarlos.
     * @return Partner
     */
    private function partnerDelivering(Basket $basket, ?string $dozens): Partner
    {
        $share = $this->em->getRepository(BasketShare::class)->find(self::SHARE_WEEKLY);
        $picked = $this->em->getRepository(WeeklyBasketStatus::class)->find(1);

        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname('Huevos ' . uniqid('', true))
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($this->group);
        $this->em->persist($partner);

        $pbs = new PartnerBasketShare();
        $pbs->setPartner($partner);
        $pbs->setBasketShare($share);
        $pbs->setIsActive(true);
        $pbs->setAmount(1);
        $pbs->setVegetablesBasketAmount(1);
        $pbs->setMonthPrice('0.00');
        $pbs->setEggMonthPrice('0.00');
        $pbs->setStartDate(new \DateTime('2020-01-01'));
        $this->em->persist($pbs);

        $wb = (new WeeklyBasket())
            ->setBasket($basket)
            ->setPartner($partner)
            ->setWeeklyBasketGroup($this->group)
            ->setWeeklyBasketStatus($picked)
            ->setBasketShare($share)
            ->setAmount(1)
            ->setDeliveryDate($basket->getDate());
        $this->em->persist($wb);

        $this->em->persist(
            (new WeeklyBasketItem())
                ->setWeeklyBasket($wb)
                ->setBasketComponent($this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES))
                ->setAmount('1.00')
        );
        if ($dozens !== null) {
            $this->em->persist(
                (new WeeklyBasketItem())
                    ->setWeeklyBasket($wb)
                    ->setBasketComponent($this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS))
                    ->setAmount($dozens)
            );
        }

        return $partner;
    }

    /**
     * Cantidad materializada de un componente en la entrega de un socio, o 0.0
     * si esa línea ya no existe.
     *
     * @param Partner $partner
     * @param Basket  $basket
     * @param int     $componentId
     * @return float
     */
    private function materializedAmount(Partner $partner, Basket $basket, int $componentId): float
    {
        $wb = $this->em->getRepository(WeeklyBasket::class)
            ->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
        if ($wb === null) {
            return 0.0;
        }

        $item = $this->em->getRepository(WeeklyBasketItem::class)
            ->findOneBy(['weeklyBasket' => $wb, 'basketComponent' => $componentId]);

        return $item === null ? 0.0 : (float) $item->getAmount();
    }

    /**
     * @param string $date Fecha del ciclo (viernes).
     * @param int    $week Nº de semana.
     * @return Basket
     */
    private function basket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);

        return $basket;
    }
}
