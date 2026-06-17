<?php

namespace App\Tests\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Security\PartnerUserProvisioner;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests del provisioning de cuentas de socix. Lógica compartida por el primer
 * acceso del magic-link (resolveOrCreate, parte de un Partner) y el SSO con
 * Google (resolveByVerifiedEmail, parte de un email verificado).
 *
 * La creación de cuentas nuevas está condicionada al toggle de configuración
 * "alta abierta" (AppSettings::SELF_REGISTRATION); provision() es la vía de
 * admin que se lo salta.
 */
class PartnerUserProvisionerTest extends TestCase
{
    /**
     * Construye la política de acceso real sobre un UserRepository simulado y
     * un toggle de alta fijo. Los tests del provisioner no quieren dobles de
     * la política (es lógica pura barata); sólo de sus dependencias de I/O.
     *
     * @param User|null $existingUser lo que devuelve el lookup por email
     * @param bool      $registrationOpen valor del toggle de alta
     */
    private function accessPolicy(?User $existingUser, bool $registrationOpen): PartnerAccessPolicy
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('loadUserByIdentifier')->willReturn($existingUser);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($registrationOpen);

        return new PartnerAccessPolicy($userRepository, $settings);
    }

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
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy(null, true),
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

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy($existing, true),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('compartido@example.com');

        $this->assertSame($existing, $provisioner->resolveOrCreate($partner));
    }

    /**
     * Sin User previo y con el alta abierta se crea uno: email/username
     * canonicalizados a minúsculas, ROLE_PARTNER, passwordSet=false y
     * password hasheado.
     */
    public function testCreatesPersistsAndCanonicalizesWhenNoUserExists(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-placeholder');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $em->expects($this->once())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $hasher,
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy(null, true),
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

    // -- gate de alta cerrada ----------------------------------------------

    /**
     * Con el alta cerrada por configuración no se crea ninguna cuenta nueva:
     * null, nada persistido, y un log informativo para diagnóstico.
     */
    public function testDoesNotCreateWhenSelfRegistrationIsClosed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy(null, false),
            $logger,
        );

        $partner = (new Partner())->setEmail('sin.cuenta@example.com');

        $this->assertNull($provisioner->resolveOrCreate($partner));
    }

    /**
     * El alta cerrada NO bloquea a quien ya tiene cuenta: los pilotos
     * provisionados por admin siguen resolviéndose con normalidad.
     */
    public function testResolvesExistingUserEvenWhenSelfRegistrationIsClosed(): void
    {
        $existing = new User();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy($existing, false),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('piloto@example.com');

        $this->assertSame($existing, $provisioner->resolveOrCreate($partner));
    }

    /**
     * provision() es la vía de admin ("dar acceso" en la ficha): crea la
     * cuenta aunque el alta esté cerrada y le concede acceso anticipado, de
     * modo que entre con el grifo global cerrado.
     */
    public function testProvisionCreatesEvenWhenSelfRegistrationIsClosed(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-placeholder');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $em->expects($this->atLeastOnce())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $hasher,
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy(null, false),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('elegida@example.com');

        $user = $provisioner->provision($partner);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('elegida@example.com', $user->getEmail());
        $this->assertSame($partner, $user->getPartner());
        $this->assertTrue($user->isEarlyAccess());
    }

    /**
     * provision() sobre una cuenta que ya existía (p. ej. Alberto, provisionado
     * antes de existir la marca) le concede el acceso anticipado y persiste el
     * cambio: el botón "dar acceso" significa "este socix puede entrar ya",
     * con cuenta nueva o vieja.
     */
    public function testProvisionGrantsEarlyAccessToExistingUser(): void
    {
        $existing = (new User())->setEmail('alberto@example.com');
        $this->assertFalse($existing->isEarlyAccess());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy($existing, false),
            $this->createMock(LoggerInterface::class),
        );

        $partner = (new Partner())->setEmail('alberto@example.com');

        $user = $provisioner->provision($partner);

        $this->assertSame($existing, $user);
        $this->assertTrue($user->isEarlyAccess());
    }

    /**
     * provision() tampoco puede hacer nada sin email: null y nada persistido.
     */
    public function testProvisionReturnsNullWhenPartnerHasNoEmail(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(PartnerRepository::class),
            $this->accessPolicy(null, false),
            $this->createMock(LoggerInterface::class),
        );

        $this->assertNull($provisioner->provision(new Partner()));
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
            $partnerRepository,
            $this->accessPolicy(null, true),
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
            $partnerRepository,
            $this->accessPolicy(null, true),
            $logger,
        );

        $this->assertNull($provisioner->resolveByVerifiedEmail('compartido@gmail.com'));
    }

    /**
     * Exactamente 1 socix activo: se provisiona su User, que queda con
     * passwordSet=true. El SSO de Google ya es una vía de acceso permanente,
     * así que el panel no debe forzarle la pantalla de configurar contraseña.
     */
    public function testResolveByVerifiedEmailProvisionsWhenExactlyOneMatch(): void
    {
        $partner = (new Partner())->setEmail('unica@gmail.com');

        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([$partner]);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-placeholder');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(User::class));
        $em->expects($this->atLeastOnce())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $hasher,
            $partnerRepository,
            $this->accessPolicy(null, true),
            $this->createMock(LoggerInterface::class),
        );

        $user = $provisioner->resolveByVerifiedEmail('unica@gmail.com');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('unica@gmail.com', $user->getEmail());
        $this->assertSame($partner, $user->getPartner());
        $this->assertTrue($user->isPasswordSet());
    }

    /**
     * Con el alta cerrada, el SSO tampoco crea cuentas: un email verificado
     * de un socix activo SIN User previo no se loguea.
     */
    public function testResolveByVerifiedEmailRespectsClosedRegistration(): void
    {
        $partner = (new Partner())->setEmail('nueva@gmail.com');

        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([$partner]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $partnerRepository,
            $this->accessPolicy(null, false),
            $this->createMock(LoggerInterface::class),
        );

        $this->assertNull($provisioner->resolveByVerifiedEmail('nueva@gmail.com'));
    }

    /**
     * Un User que ya existía con passwordSet=false (p. ej. provisionado pero
     * que nunca llegó a fijar contraseña) y que ahora entra por SSO: se le
     * marca passwordSet=true para que el panel no le bloquee con el setup.
     * Se reutiliza el User existente (no se persiste uno nuevo) y se hace flush
     * del cambio.
     */
    public function testResolveByVerifiedEmailMarksPasswordSetOnExistingUnsetUser(): void
    {
        $partner = (new Partner())->setEmail('existente@gmail.com');

        $existing = (new User())->setEmail('existente@gmail.com');
        $existing->setPasswordSet(false);

        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveByEmail')->willReturn([$partner]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $provisioner = new PartnerUserProvisioner(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $partnerRepository,
            $this->accessPolicy($existing, true),
            $this->createMock(LoggerInterface::class),
        );

        $user = $provisioner->resolveByVerifiedEmail('existente@gmail.com');

        $this->assertSame($existing, $user);
        $this->assertTrue($user->isPasswordSet());
    }
}
