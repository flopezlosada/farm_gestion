<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Recorre el motor de reparto ENTERO con un punto de cadencia mensual, contra
 * BBDD real: nodo, grupo de recogida, socio y cesta mensual, resueltos por
 * {@see WeeklyBasketGenerator::projectForBasket}, que es el mismo camino que
 * usa el listado (cadencia del nodo → semanas servidas → query node-aware).
 *
 * Hasta ahora la cadencia mensual sólo tenía cobertura unitaria con mocks:
 * cada pieza estaba probada por separado, pero nada garantizaba que encajaran
 * con datos reales. Esto lo cierra.
 *
 * Autocontenido: crea su grupo, su socio, su cesta y sus semanas, y lo borra
 * todo al final para no ensuciar db_test. El nodo sí viene de fixtures
 * (El Berrueco, id 4, jueves, 2ª semana del mes).
 *
 * Octubre 2026 como mes de trabajo: sus viernes son 2, 9, 16, 23 y 30, con
 * jueves 1, 8, 15, 22 y 29, así que el 2º jueves (día 8) llega por el ciclo
 * del viernes 9. Se eligió por estar vacío de Baskets en las fixtures, para
 * que la ventana de ±8 días del resolver mensual no arrastre semanas ajenas.
 */
class MonthlyNodeDeliveryTest extends KernelTestCase
{
    /** Viernes-ciclo de octubre 2026. El 2º jueves cae en el del día 9. */
    private const FRIDAYS = ['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'];
    private const FRIDAY_OF_SECOND_THURSDAY = '2026-10-09';

    private const MONTHLY_NODE_ID = 4;

    /** @var int[] */
    private array $createdBasketIds = [];
    private ?int $partnerId = null;
    private ?int $groupId = null;
    private ?int $shareId = null;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Un socio de un punto mensual recoge en la semana que abre el punto, y en
     * ninguna otra del mes. Es la garantía que sostiene todo lo demás: si
     * fallara, o no recogería nunca o recogería todas las semanas.
     */
    public function testPartnerOfMonthlyNodeIsProjectedOnlyOnTheNodesWeek(): void
    {
        self::bootKernel();
        $this->seedScenario(2);

        $generator = static::getContainer()->get(WeeklyBasketGenerator::class);

        foreach (self::FRIDAYS as $friday) {
            $basket = $this->basketFor($friday);
            $projected = $this->partnerIdsIn($generator->projectForBasket($basket));

            if ($friday === self::FRIDAY_OF_SECOND_THURSDAY) {
                $this->assertContains(
                    $this->partnerId,
                    $projected,
                    'El socio debería recoger en el ciclo del 2º jueves, que es cuando abre su punto.'
                );
            } else {
                $this->assertNotContains(
                    $this->partnerId,
                    $projected,
                    sprintf('El punto no abre en el ciclo del %s: ahí no debería repartir a nadie.', $friday)
                );
            }
        }
    }

    /**
     * El socio recoge aunque su ficha diga otra semana. Pasa de verdad: si
     * administración cambia la semana que abre el punto, los socios ya dados de
     * alta conservan la anterior, y sin este blindaje desaparecerían del
     * listado sin ningún aviso.
     */
    public function testPartnerStillDeliversWhenHisFileDisagreesWithTheNode(): void
    {
        self::bootKernel();
        // El punto abre la 2ª semana; la ficha del socio dice la 3ª.
        $this->seedScenario(3);

        $generator = static::getContainer()->get(WeeklyBasketGenerator::class);
        $basket = $this->basketFor(self::FRIDAY_OF_SECOND_THURSDAY);

        $this->assertContains(
            $this->partnerId,
            $this->partnerIdsIn($generator->projectForBasket($basket)),
            'La entrega del punto mensual debe servir cualquier posición que el socio tenga en ficha.'
        );
    }

    /**
     * Y sigue sin repartir fuera de su semana por mucho que la ficha del socio
     * apunte a otra: el blindaje amplía a quién sirve una entrega real, no
     * inventa entregas.
     */
    public function testDisagreeingFileDoesNotCreateDeliveriesOnOtherWeeks(): void
    {
        self::bootKernel();
        $this->seedScenario(3);

        $generator = static::getContainer()->get(WeeklyBasketGenerator::class);
        $basket = $this->basketFor('2026-10-16');

        $this->assertNotContains(
            $this->partnerId,
            $this->partnerIdsIn($generator->projectForBasket($basket)),
            'El ciclo del 3er jueves no es el del punto: no debe repartir.'
        );
    }

    /**
     * Siembra el escenario: grupo colgado de El Berrueco, socio dentro y cesta
     * mensual activa, más los cinco viernes de octubre 2026.
     *
     * @param int $dayMonthOrder Semana que declara la ficha del socio.
     * @return void
     */
    private function seedScenario(int $dayMonthOrder): void
    {
        $em = $this->em();

        $node = $em->getRepository(Node::class)->find(self::MONTHLY_NODE_ID);
        $this->assertNotNull($node, 'Las fixtures deberían sembrar el punto mensual El Berrueco.');
        $this->assertTrue($node->isMonthly());

        $group = (new WeeklyBasketGroup())
            ->setName('TEST Berrueco ' . uniqid())
            ->setColor('#abcabc')
            ->setNode($node);
        $em->persist($group);

        $partner = (new Partner())
            ->setname('TEST')
            ->setSurname('Mensual ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($group);
        $em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find(BasketShare::ID_MONTHLY));
        $share->setStartDate(new \DateTime('2026-01-01'));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setDayMonthOrder($dayMonthOrder);
        // Precios a cero: la tabla los exige NOT NULL y aquí no se prueba tarifa.
        $share->setMonthPrice('0');
        $share->setEggMonthPrice('0');
        // Sin turno: en un punto con calendario propio el turno A/B no pinta nada.
        $share->setDeliveryGroup(null);
        $em->persist($share);

        $baskets = [];
        foreach (self::FRIDAYS as $friday) {
            $date = new \DateTimeImmutable($friday);
            $basket = new Basket();
            $basket->setDate(\DateTime::createFromImmutable($date));
            $basket->setWeek((int) $date->format('W'));
            $basket->setAmount(1);
            $em->persist($basket);
            $baskets[] = $basket;
        }

        $em->flush();

        $this->groupId = $group->getId();
        $this->partnerId = $partner->getId();
        $this->shareId = $share->getId();
        $this->createdBasketIds = array_map(
            static fn (Basket $basket): int => $basket->getId(),
            $baskets,
        );
    }

    /**
     * @param string $friday Fecha 'Y-m-d' del viernes-ciclo.
     * @return Basket
     */
    private function basketFor(string $friday): Basket
    {
        $basket = $this->em()->getRepository(Basket::class)->findOneBy(['date' => new \DateTime($friday)]);
        $this->assertNotNull($basket, sprintf('Debería existir el ciclo del %s.', $friday));

        return $basket;
    }

    /**
     * @param PartnerBasketShare[] $shares
     * @return int[] IDs de los socios proyectados.
     */
    private function partnerIdsIn(array $shares): array
    {
        return array_map(
            static fn (PartnerBasketShare $share): int => $share->getPartner()->getId(),
            $shares,
        );
    }

    protected function tearDown(): void
    {
        if (self::$booted && $this->partnerId !== null) {
            $em = $this->em();
            foreach ([
                [PartnerBasketShare::class, $this->shareId],
                [Partner::class, $this->partnerId],
                [WeeklyBasketGroup::class, $this->groupId],
            ] as [$class, $id]) {
                $entity = $id !== null ? $em->getRepository($class)->find($id) : null;
                if ($entity !== null) {
                    $em->remove($entity);
                }
            }
            foreach ($this->createdBasketIds as $basketId) {
                $basket = $em->getRepository(Basket::class)->find($basketId);
                if ($basket !== null) {
                    $em->remove($basket);
                }
            }
            $em->flush();
        }

        $this->createdBasketIds = [];
        $this->partnerId = null;
        $this->groupId = null;
        $this->shareId = null;

        parent::tearDown();
    }
}
