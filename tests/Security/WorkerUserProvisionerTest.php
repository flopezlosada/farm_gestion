<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Entity\Worker;
use App\Repository\UserRepository;
use App\Security\WorkerUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Resolución de la cuenta de un trabajador a partir del email que Google da por
 * verificado. La regla que se protege: el SSO sólo deja entrar a cuentas que la
 * administración ya dio de alta con un trabajador vinculado; nunca crea una.
 */
class WorkerUserProvisionerTest extends TestCase
{
    /**
     * Provisioner cuyo lookup por email devuelve la cuenta dada.
     *
     * @param User|null              $found Lo que devuelve loadUserByIdentifier.
     * @param EntityManagerInterface $em    EntityManager (mock con expectativas).
     * @return WorkerUserProvisioner
     */
    private function provisioner(?User $found, EntityManagerInterface $em): WorkerUserProvisioner
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('loadUserByIdentifier')->willReturn($found);

        return new WorkerUserProvisioner($em, $users, $this->createMock(UserPasswordHasherInterface::class));
    }

    public function testEmailSinCuentaNoEntraNiCreaNada(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $this->assertNull($this->provisioner(null, $em)->resolveByVerifiedEmail('alguien@gmail.com'));
    }

    public function testCuentaSinTrabajadorNoEntraPorEstaVia(): void
    {
        // Una cuenta de socix o del equipo no se resuelve aquí: eso es cosa del
        // provisioner de socixs, que tiene sus propias reglas (alta abierta…).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $user = (new User())->setEmail('socia@gmail.com')->setPasswordSet(false);

        $this->assertNull($this->provisioner($user, $em)->resolveByVerifiedEmail('socia@gmail.com'));
        $this->assertFalse($user->isPasswordSet());
    }

    public function testTrabajadorRecienDadoDeAltaEntraYNoSeLePideContrasena(): void
    {
        // Recién dado de alta, la cuenta tiene passwordSet = false; entrar con
        // Google cuenta como haber elegido credencial.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $user = (new User())->setEmail('trabajadora@gmail.com')->setPasswordSet(false)->setWorker(new Worker());

        $this->assertSame($user, $this->provisioner($user, $em)->resolveByVerifiedEmail('trabajadora@gmail.com'));
        $this->assertTrue($user->isPasswordSet());
    }

    public function testTrabajadorQueYaEligioCredencialNoEscribe(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $user = (new User())->setEmail('trabajadora@gmail.com')->setPasswordSet(true)->setWorker(new Worker());

        $this->assertSame($user, $this->provisioner($user, $em)->resolveByVerifiedEmail('trabajadora@gmail.com'));
    }
}
