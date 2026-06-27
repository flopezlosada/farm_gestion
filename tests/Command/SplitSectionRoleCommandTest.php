<?php

namespace App\Tests\Command;

use App\Command\SplitSectionRoleCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test del comando que migra los roles al partir una sección. El
 * EntityManager se simula: el comando muta los objetos User en memoria, así
 * que basta inspeccionar sus roles tras ejecutar. Cubre lo sensible: a quién
 * SÍ y a quién NO se le añade el _EDIT, idempotencia y dry-run.
 */
class SplitSectionRoleCommandTest extends TestCase
{
    /** @param User[] $users */
    private function runWith(array $users, array $args): CommandTester
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn($users);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repo);

        $tester = new CommandTester(new SplitSectionRoleCommand($em));
        $tester->execute($args);

        return $tester;
    }

    private function userWith(array $roles): User
    {
        return (new User())->setUsername('u-' . implode('-', $roles))->setRoles($roles);
    }

    public function testAddsEditRoleToBaseRoleHolders(): void
    {
        $user = $this->userWith(['ROLE_GESTION_SOCIXS']);

        $tester = $this->runWith([$user], ['section' => 'socixs']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertContains('ROLE_GESTION_SOCIXS_EDIT', $user->getRoles());
        $this->assertContains('ROLE_GESTION_SOCIXS', $user->getRoles());
    }

    public function testPreservesOtherStoredRoles(): void
    {
        $user = $this->userWith(['ROLE_GESTION_SOCIXS', 'ROLE_GESTION_REPARTO']);

        $this->runWith([$user], ['section' => 'socixs']);

        $roles = $user->getRoles();
        $this->assertContains('ROLE_GESTION_SOCIXS_EDIT', $roles);
        $this->assertContains('ROLE_GESTION_REPARTO', $roles);
    }

    public function testIsIdempotent(): void
    {
        $user = $this->userWith(['ROLE_GESTION_SOCIXS', 'ROLE_GESTION_SOCIXS_EDIT']);

        $tester = $this->runWith([$user], ['section' => 'socixs']);

        // No reinyecta nada y el comando lo reporta como "nada que migrar".
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Nada que migrar', $tester->getDisplay());
        $this->assertSame(
            1,
            count(array_keys($user->getRoles(), 'ROLE_GESTION_SOCIXS_EDIT', true)),
            'El _EDIT no debe duplicarse.'
        );
    }

    public function testDoesNotTouchAdmins(): void
    {
        // El admin entra por jerarquía (ROLE_ADMIN), no por el rol base literal.
        $admin = $this->userWith(['ROLE_ADMIN']);

        $tester = $this->runWith([$admin], ['section' => 'socixs']);

        $this->assertStringContainsString('Nada que migrar', $tester->getDisplay());
        $this->assertNotContains('ROLE_GESTION_SOCIXS_EDIT', $admin->getRoles());
    }

    public function testDryRunReportsButDoesNotMutate(): void
    {
        $user = $this->userWith(['ROLE_GESTION_SOCIXS']);

        $tester = $this->runWith([$user], ['section' => 'socixs', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('dry-run', $tester->getDisplay());
        $this->assertNotContains('ROLE_GESTION_SOCIXS_EDIT', $user->getRoles());
    }

    public function testUnknownSectionIsInvalid(): void
    {
        $tester = $this->runWith([], ['section' => 'no-existe']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testNonSplitSectionIsInvalid(): void
    {
        // granja no está partida (edit = null): no hay rol de escritura.
        $tester = $this->runWith([], ['section' => 'granja']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
