<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\Worker;
use PHPUnit\Framework\TestCase;

/**
 * El rol ROLE_WORKER se DERIVA de tener un Worker vinculado, igual que
 * ROLE_PARTNER se deriva de tener un Partner. No se guarda en la columna `roles`.
 */
class UserWorkerRoleTest extends TestCase
{
    public function testUserSinWorkerNoTieneRoleWorker(): void
    {
        $user = new User();

        $this->assertNotContains('ROLE_WORKER', $user->getRoles());
    }

    public function testUserConWorkerDerivaRoleWorker(): void
    {
        $user = (new User())->setWorker(new Worker());

        $this->assertContains('ROLE_WORKER', $user->getRoles());
    }

    public function testFacetasSocixYLaboralConvivenEnLosRoles(): void
    {
        $user = (new User())
            ->setPartner(new Partner())
            ->setWorker(new Worker());

        $roles = $user->getRoles();

        $this->assertContains('ROLE_PARTNER', $roles);
        $this->assertContains('ROLE_WORKER', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }
}
