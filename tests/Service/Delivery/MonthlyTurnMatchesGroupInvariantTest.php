<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\Invariant\MonthlyTurnMatchesGroupInvariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cubre la ley L31 ({@see MonthlyTurnMatchesGroupInvariant}): una cesta mensual
 * sin turno en un grupo que de hecho sólo abre en uno de los dos turnos de
 * viernes. Lo que se prueba es dónde está la frontera — cuándo se puede afirmar
 * que un grupo abre nada más que en un turno y cuándo no —, porque es ahí donde
 * la ley acierta o hace ruido.
 *
 * Cada caso siembra su propio punto semanal para no depender de la composición
 * de los grupos de las fixtures, que cambia.
 */
class MonthlyTurnMatchesGroupInvariantTest extends KernelTestCase
{
    /** @var int[] Ids sembrados, en orden inverso de borrado. */
    private array $shareIds = [];
    /** @var int[] */
    private array $partnerIds = [];
    private ?int $groupId = null;
    private ?int $nodeId = null;

    /**
     * El caso de Alcobendas: quincenales todos en el turno B y una mensual sin
     * turno, que por eso cuenta su entrega sobre los viernes del mes.
     */
    public function testAvisaDelMensualSinTurnoCuandoElGrupoAbreEnUnSoloTurno(): void
    {
        $em = $this->bootEm();
        $this->seedGroup($em);
        $this->seedShare($em, BasketShare::ID_BIWEEKLY, PartnerBasketShare::DELIVERY_GROUP_B);
        $monthlyId = $this->seedShare($em, BasketShare::ID_MONTHLY, null, 2);

        $violations = $this->matching($this->check(), $monthlyId);

        $this->assertCount(1, $violations, 'La mensual sin turno del grupo de un solo turno debe avisar.');
        $this->assertStringContainsString('turno B', $violations[0], 'El aviso debe decir qué turno poner.');
    }

    /**
     * Un socio con cesta semanal abre el punto todos los viernes, así que la
     * mensual está bien sin turno: su 2ª entrega del mes siempre tiene reparto.
     */
    public function testNoAvisaSiAlguienDelGrupoRecogeTodasLasSemanas(): void
    {
        $em = $this->bootEm();
        $this->seedGroup($em);
        $this->seedShare($em, BasketShare::ID_BIWEEKLY, PartnerBasketShare::DELIVERY_GROUP_B);
        $this->seedShare($em, BasketShare::IDS_WEEKLY[0]);
        $monthlyId = $this->seedShare($em, BasketShare::ID_MONTHLY, null, 2);

        $this->assertSame([], $this->matching($this->check(), $monthlyId));
    }

    /**
     * Con quincenales en los dos turnos el grupo abre cada viernes por su
     * cuenta: no hay ningún turno que imponer a la mensual.
     */
    public function testNoAvisaSiElGrupoTieneQuincenalesEnLosDosTurnos(): void
    {
        $em = $this->bootEm();
        $this->seedGroup($em);
        $this->seedShare($em, BasketShare::ID_BIWEEKLY, PartnerBasketShare::DELIVERY_GROUP_A);
        $this->seedShare($em, BasketShare::ID_BIWEEKLY, PartnerBasketShare::DELIVERY_GROUP_B);
        $monthlyId = $this->seedShare($em, BasketShare::ID_MONTHLY, null, 2);

        $this->assertSame([], $this->matching($this->check(), $monthlyId));
    }

    /**
     * Y la ficha ya arreglada no vuelve a salir: es el estado al que la ley
     * empuja, y si siguiera avisando el informe se volvería ruido.
     */
    public function testNoAvisaSiLaMensualYaLlevaElTurno(): void
    {
        $em = $this->bootEm();
        $this->seedGroup($em);
        $this->seedShare($em, BasketShare::ID_BIWEEKLY, PartnerBasketShare::DELIVERY_GROUP_B);
        $monthlyId = $this->seedShare($em, BasketShare::ID_MONTHLY, PartnerBasketShare::DELIVERY_GROUP_B, 2);

        $this->assertSame([], $this->matching($this->check(), $monthlyId));
    }

    /**
     * Violaciones de la ley sobre el estado actual. Se instancia a mano, como
     * en {@see ShareFitsNodeOfferInvariantTest}: el compilador puede inlinear
     * un servicio que sólo consume el iterador por tag y dejarlo sin id que
     * pedir al contenedor.
     *
     * @return list<string>
     */
    private function check(): array
    {
        $invariant = new MonthlyTurnMatchesGroupInvariant(
            static::getContainer()->get('doctrine')->getManager(),
        );

        return $invariant->check(new \DateTimeImmutable('today'));
    }

    /**
     * Las violaciones que hablan de la cesta sembrada, para no depender de lo
     * que las fixtures tengan sucio por su cuenta.
     *
     * @param list<string> $violations
     * @param int          $shareId    Cesta sembrada.
     * @return list<string>
     */
    private function matching(array $violations, int $shareId): array
    {
        $needle = sprintf('[cesta %d]', $shareId);

        return array_values(array_filter(
            $violations,
            static fn (string $line): bool => str_contains($line, $needle)
        ));
    }

    private function bootEm(): EntityManagerInterface
    {
        self::bootKernel();

        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Punto SEMANAL nuevo y su grupo de recogida, ambos desechables.
     *
     * El sistema asume hoy un único punto semanal (Torremocha) en el generador;
     * la ley no, así que sembrar otro es inocuo mientras el test sólo la
     * ejecute a ella y lo borre al terminar.
     */
    private function seedGroup(EntityManagerInterface $em): void
    {
        $node = (new Node())
            ->setName('TEST Punto semanal ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $group = (new WeeklyBasketGroup())
            ->setName('TEST Grupo ' . uniqid())
            ->setColor('#abcabc')
            ->setNode($node);

        $em->persist($node);
        $em->persist($group);
        $em->flush();

        $this->nodeId = $node->getId();
        $this->groupId = $group->getId();
    }

    /**
     * Un socio del grupo sembrado con una cesta vigente.
     *
     * @param EntityManagerInterface $em
     * @param int                    $basketShareId Modalidad del catálogo.
     * @param string|null            $turn          Turno de viernes, o null.
     * @param int|null               $monthOrder    Entrega del mes, para las mensuales.
     * @return int Id de la cesta creada.
     */
    private function seedShare(
        EntityManagerInterface $em,
        int $basketShareId,
        ?string $turn = null,
        ?int $monthOrder = null,
    ): int {
        $partner = (new Partner())
            ->setName('TEST')
            ->setSurname('Turno Grupo ' . uniqid())
            ->setStatus(Partner::STATUS_ACTIVO);
        $partner->setWeeklyBasketGroup($em->getRepository(WeeklyBasketGroup::class)->find($this->groupId));

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find($basketShareId));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setDeliveryGroup($turn);
        $share->setDayMonthOrder($monthOrder);

        $em->persist($partner);
        $em->persist($share);
        $em->flush();

        $this->partnerIds[] = $partner->getId();
        $this->shareIds[] = $share->getId();

        return $share->getId();
    }

    protected function tearDown(): void
    {
        if ($this->nodeId !== null) {
            $em = static::getContainer()->get('doctrine')->getManager();
            foreach ($this->shareIds as $id) {
                $em->remove($em->getRepository(PartnerBasketShare::class)->find($id));
            }
            $em->flush();
            foreach ($this->partnerIds as $id) {
                $em->remove($em->getRepository(Partner::class)->find($id));
            }
            $em->flush();
            $em->remove($em->getRepository(WeeklyBasketGroup::class)->find($this->groupId));
            $em->remove($em->getRepository(Node::class)->find($this->nodeId));
            $em->flush();
        }

        $this->shareIds = [];
        $this->partnerIds = [];
        $this->groupId = null;
        $this->nodeId = null;

        parent::tearDown();
    }
}
