<?php

namespace App\Tests\Command;

use App\Command\ValidatePartnersConsistencyCommand;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test del comando de consistencia de socios. El EntityManager se
 * simula para alimentar al comando con escenarios construidos a mano, sin
 * tocar BBDD.
 *
 * Sub-fase 8.8d (2026-05-27).
 */
class ValidatePartnersConsistencyCommandTest extends TestCase
{
    private const MODALITY_WEEKLY = 1;
    private const MODALITY_BIWEEKLY = 2;

    public function testModeloCoherenteDevuelveSuccess(): void
    {
        $partner = $this->partner(1, 'Coherente');
        $share = $this->share($partner, self::MODALITY_BIWEEKLY, 'A');

        $tester = $this->runWith([$partner], [$share]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Sin incoherencias', $tester->getDisplay());
    }

    public function testDetectaSharePartnerNoReciproco(): void
    {
        $a = $this->partner(1, 'Ana');
        $b = $this->partner(2, 'Berta');
        $a->setSharePartner($b); // B no apunta de vuelta a A

        $tester = $this->runWith([$a, $b], []);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Ana', $tester->getDisplay());
    }

    public function testDetectaQuincenalSinCohorte(): void
    {
        $partner = $this->partner(1, 'Quincenal');
        $share = $this->share($partner, self::MODALITY_BIWEEKLY, null);

        $tester = $this->runWith([$partner], [$share]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('sin cohorte', $tester->getDisplay());
    }

    public function testDetectaDeliveryGroupEnSemanal(): void
    {
        $partner = $this->partner(1, 'Semanal');
        $share = $this->share($partner, self::MODALITY_WEEKLY, 'B');

        $tester = $this->runWith([$partner], [$share]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testDetectaMultiplesPbsActivos(): void
    {
        $partner = $this->partner(1, 'Doble');
        $shareA = $this->share($partner, self::MODALITY_BIWEEKLY, 'A');
        $shareB = $this->share($partner, self::MODALITY_BIWEEKLY, 'A');

        $tester = $this->runWith([$partner], [$shareA, $shareB]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('PBS activos', $tester->getDisplay());
    }

    public function testFamiliarSinGrupoNoEsProblema(): void
    {
        $principal = $this->partner(1, 'Principal');
        $familiar = new Partner();
        $familiar->setName('Familiar');
        $familiar->setSurname('Socio');
        $familiar->setIsActive(true);
        $familiar->setParent($principal); // recoge con el principal, sin grupo propio
        $this->withId($familiar, 2);

        $tester = $this->runWith([$familiar], []);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testDetectaPagadorInactivo(): void
    {
        $partner = $this->partner(1, 'Pagado');
        $payer = $this->partner(2, 'Pagador');
        $payer->setIsActive(false);
        $share = $this->share($partner, self::MODALITY_BIWEEKLY, 'A');
        $share->setPayerPartner($payer);

        $tester = $this->runWith([$partner], [$share]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('inactivo', $tester->getDisplay());
    }

    /**
     * Ejecuta el comando con un EntityManager simulado que devuelve los
     * socios y suscripciones dados.
     *
     * @param Partner[] $partners
     * @param PartnerBasketShare[] $shares
     * @return CommandTester
     */
    private function runWith(array $partners, array $shares): CommandTester
    {
        $partnerRepo = $this->createMock(EntityRepository::class);
        $partnerRepo->method('findBy')->willReturn($partners);

        $shareRepo = $this->createMock(EntityRepository::class);
        $shareRepo->method('findBy')->willReturn($shares);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [Partner::class, $partnerRepo],
            [PartnerBasketShare::class, $shareRepo],
        ]);

        $tester = new CommandTester(new ValidatePartnersConsistencyCommand($em));
        $tester->execute([]);

        return $tester;
    }

    /**
     * Socio activo con grupo de recogida y nodo (no dispara la regla de
     * "socio sin nodo"); listo para aislar otras reglas.
     */
    private function partner(int $id, string $name): Partner
    {
        $partner = new Partner();
        $partner->setName($name);
        $partner->setSurname('Socio');
        $partner->setIsActive(true);

        $group = new WeeklyBasketGroup();
        $group->setName('Grupo');
        $group->setNode(new Node());
        $partner->setWeeklyBasketGroup($group);

        return $this->withId($partner, $id);
    }

    /**
     * Suscripción activa con la modalidad y cohorte dadas. La modalidad se
     * simula con un BasketShare mockeado por su id de catálogo.
     */
    private function share(Partner $partner, int $modalityId, ?string $cohort): PartnerBasketShare
    {
        $basketShare = $this->createMock(BasketShare::class);
        $basketShare->method('getId')->willReturn($modalityId);
        $basketShare->method('getName')->willReturn('Modalidad ' . $modalityId);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($basketShare);
        $share->setDeliveryGroup($cohort);
        $share->setIsActive(true);

        return $share;
    }

    /**
     * Inyecta un id en una entidad (las entidades no exponen setId).
     *
     * @template T of object
     * @param T $entity
     * @param int $id
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);

        return $entity;
    }
}
