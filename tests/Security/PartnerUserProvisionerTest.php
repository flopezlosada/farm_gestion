<?php

namespace App\Tests\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Security\PartnerUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests del provisioning de cuentas de socix. Lógica compartida por el primer
 * acceso del magic-link (resolveOrCreate, parte de un Partner) y el SSO con
 * Google (resolveByVerifiedEmail, parte de un email verificado).
 */
class PartnerUserProvisionerTest extends TestCase
{
    // -- resolveOrCreate(Partner) ------------------------------------------

    /**
     * Sin email no hay login posible (el acceso va por correo): se devuelve
     * null y no se toca la BBDD.
     */
    public function testReturnsNullAndPersistsNothingWhenPartnerHasNoEmail(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(UserRepository::class),
            $this->createMock(PartnerRepository::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->assertNull($provisioner->resolveOrCreate(new Partner()));
    }

    /**
     * Si ya existe un User con ese email (caso familia que comparte buzón),
     * se reutiliza el existente sin crear ni persistir uno nuevo.
     */
    public function testReturnsExistingUserWithoutCreatingANewOne(): void
    {
        $existing = new User();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('loadUserByIdentifier')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $userRepository,
            $this->createMock(PartnerRepository::class),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('compartido@example.com');

        $this->assertSame($existing, $provisioner->resolveOrCreate($partner));
    }

    /**
     * Sin User previo se crea uno: email/username canonicalizados a
     * minúsculas, ROLE_PARTNER, passwordSet=false y password hasheado.
     */
    public function testCreatesPersistsAndCanonicalizesWhenNoUserExists(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('loadUserByIdentifier')->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-placeholder');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $em->expects($this->once())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $hasher,
            $userRepository,
            $this->createMock(PartnerRepository::class),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('  Nueva.Socia@Example.COM ');

        $user = $provisioner->resolveOrCreate($partner);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('nueva.socia@example.com', $user->getEmail());
        $this->assertSame('nueva.socia@example.com', $user->getUsername());
        $this->assertContains('ROLE_PARTNER', $user->getRoles());
        $this->assertFalse($user->isPasswordSet());
        $this->assertSame($partner, $user->getPartner());
        $this->assertSame('hashed-placeholder', $user->getPassword());
    }

    // -- resolveByVerifiedEmail(string) (política del SSO) -----------------

    /**
     * 0 socixs activos con ese email: el email no pertenece a ningún socix,
     * no se loguea ni se crea nada.
     */
    public function testResolveByVerifiedEmailReturnsNullWhenNoActiveMatch(): void
    {
        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(UserRepository::class),
            $partnerRepository,
            $this->createMock(LoggerInterface::class),
        );

        $this->assertNull($provisioner->resolveByVerifiedEmail('desconocido@gmail.com'));
    }

    /**
     * >1 socixs activos con ese email: ambiguo (buzón compartido). No se
     * adivina; se rechaza el login y se deja un warning para resolución manual.
     */
    public function testResolveByVerifiedEmailReturnsNullAndLogsWhenAmbiguous(): void
    {
        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([new Partner(), new Partner()]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(UserRepository::class),
            $partnerRepository,
            $logger,
        );

        $this->assertNull($provisioner->resolveByVerifiedEmail('compartido@gmail.com'));
    }

    /**
     * Exactamente 1 socix activo: se provisiona (o recupera) su User.
     */
    public function testResolveByVerifiedEmailProvisionsWhenExactlyOneMatch(): void
    {
        $partner = (new Partner())->setEmail('unica@gmail.com');

        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([$partner]);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('loadUserByIdentifier')->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-placeholder');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $em->expects($this->once())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $hasher,
            $userRepository,
            $partnerRepository,
            $this->createMock(LoggerInterface::class),
        );

        $user = $provisioner->resolveByVerifiedEmail('unica@gmail.com');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('unica@gmail.com', $user->getEmail());
        $this->assertSame($partner, $user->getPartner());
    }
}
