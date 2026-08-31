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
    private const SHARE_MONTHLY = 3;

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

    /**
     * Finder mensual node-aware: un mensual SIN turno empareja su orden contra
     * las posiciones del mes (los viernes), y uno ANCLADO a un turno lo empareja
     * contra las posiciones de ese turno — y sólo si el turno que reparte esta
     * semana es el suyo. Es la mitad de query del caso Alcobendas; la otra mitad
     * (calcular las posiciones de cada lista) vive en
     * {@see \App\Service\Delivery\MonthlyOperativeOrderResolver}.
     */
    public function testMonthlyFinderDistingueSinTurnoDeAncladoAUnTurno(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $basket = new Basket();
        $basket->setDate(new \DateTime('2099-07-31'));
        $basket->setWeek(31);
        $basket->setAmount(1);
        $em->persist($basket);

        $monthly = $em->getRepository(BasketShare::class)->find(self::SHARE_MONTHLY);
        $this->assertNotNull($monthly, 'El catálogo debe tener la cesta mensual (id 3).');

        // El basket sirve la posición 3 del mes y la posición 1 del turno B.
        $sinTurnoDentro = $this->makeMonthlyShare($em, $monthly, 'RepoTest Mensual sin turno 3', 3, null);
        $sinTurnoFuera  = $this->makeMonthlyShare($em, $monthly, 'RepoTest Mensual sin turno 1', 1, null);
        $turnoBDentro   = $this->makeMonthlyShare($em, $monthly, 'RepoTest Mensual turno B 1', 1, 'B');
        $turnoBFuera    = $this->makeMonthlyShare($em, $monthly, 'RepoTest Mensual turno B 3', 3, 'B');
        $turnoAFuera    = $this->makeMonthlyShare($em, $monthly, 'RepoTest Mensual turno A 1', 1, 'A');
        $em->flush();

        $result = $em->getRepository(PartnerBasketShare::class)->findBasketPartnersMonthlyNodeAware(
            $basket,
            self::SHARE_MONTHLY,
            [3],        // órdenes del mes que sirve este basket
            [],         // sin nodos biweekly activos
            false,
            'B',        // turno que reparte esta semana
            [1],        // órdenes del turno B que sirve este basket
        );
        $ids = array_map(static fn (PartnerBasketShare $s): int => $s->getId(), $result);

        $this->assertContains($sinTurnoDentro->getId(), $ids, 'Sin turno, el orden cuenta los viernes del mes.');
        $this->assertNotContains($sinTurnoFuera->getId(), $ids, 'Sin turno, un orden que este basket no sirve queda fuera.');
        $this->assertContains($turnoBDentro->getId(), $ids, 'Anclado al turno que reparte hoy, empareja contra las posiciones del turno.');
        $this->assertNotContains($turnoBFuera->getId(), $ids, 'Anclado al turno, un orden fuera de las posiciones del turno queda fuera.');
        $this->assertNotContains($turnoAFuera->getId(), $ids, 'Anclado al OTRO turno, no recoge esta semana.');
    }

    /**
     * Mensual con orden y, opcionalmente, turno al que anclarlo. Sin grupo de
     * recogida: la rama weekly del finder acepta `n.id IS NULL`.
     */
    private function makeMonthlyShare(
        EntityManagerInterface $em,
        BasketShare $monthly,
        string $name,
        int $dayMonthOrder,
        ?string $deliveryGroup,
    ): PartnerBasketShare {
        $partner = new Partner();
        $partner->setName($name);
        $em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($monthly);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setDayMonthOrder($dayMonthOrder);
        $share->setDeliveryGroup($deliveryGroup);
        $em->persist($share);

        return $share;
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
