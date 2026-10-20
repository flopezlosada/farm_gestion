<?php

namespace App\Tests\Security;

use App\Security\ManagementVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * `is_granted('MANAGEMENT')` decide si alguien ve y entra a la zona de gestión.
 * Se prueba con la jerarquía de las secciones partidas en lectura/escritura,
 * que es donde fallaban las listas copiadas a mano.
 */
class ManagementVoterTest extends TestCase
{
    /**
     * Voto del voter para una sesión con los roles almacenados dados.
     *
     * @param string[] $roles
     * @return int Uno de VoterInterface::ACCESS_*.
     */
    private function vote(array $roles): int
    {
        $voter = new ManagementVoter(new RoleHierarchy([
            'ROLE_GESTION_CONTABILIDAD_EDIT' => ['ROLE_GESTION_CONTABILIDAD'],
        ]));
        $token = new UsernamePasswordToken(new InMemoryUser('alguien', null, $roles), 'main', $roles);

        return $voter->vote($token, null, [ManagementVoter::MANAGEMENT]);
    }

    public function testSocixYTrabajadorSinRolDeGestionNoEntran(): void
    {
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote(['ROLE_USER', 'ROLE_PARTNER', 'ROLE_WORKER']));
    }

    public function testRolDeGestionDeUnaSeccionRecienteEntra(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(['ROLE_USER', 'ROLE_GESTION_VENCIMIENTOS']));
    }

    public function testQuienSoloTieneElEditEntraPorJerarquia(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(['ROLE_USER', 'ROLE_GESTION_CONTABILIDAD_EDIT']));
    }

    public function testNoVotaOtrosAtributos(): void
    {
        $voter = new ManagementVoter(new RoleHierarchy([]));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_ADMIN']));
    }
}
