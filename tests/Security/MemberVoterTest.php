<?php

namespace App\Tests\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Security\MemberVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * `MEMBER_AREA` sólo cierra el paso a quien tiene una ficha marcada como no socia.
 * Todo lo demás tiene que seguir entrando como entraba: es una comprobación que
 * se SUMA a las que ya había, y si negara de más dejaría fuera a gente real.
 */
class MemberVoterTest extends TestCase
{
    /**
     * Voto del voter para una sesión con esta cuenta.
     *
     * @return int uno de VoterInterface::ACCESS_*
     */
    private function vote(User $user): int
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return (new MemberVoter())->vote($token, null, [MemberVoter::MEMBER_AREA]);
    }

    public function testUnaSociaEntra(): void
    {
        $user = (new User())->setPartner((new Partner())->setName('Socia'));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user));
    }

    public function testQuienRecibeCestaSinSerSociaNoEntra(): void
    {
        $user = (new User())->setPartner((new Partner())->setName('Trabajadora')->setMember(false));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user));
    }

    /**
     * Un admin sin ficha (como el de producción) sigue entrando: el voter no
     * exige ficha, sólo niega la de no socia.
     */
    public function testUnaCuentaSinFichaEntra(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote((new User())->setRoles(['ROLE_ADMIN'])));
    }

    /**
     * Sin sesión no decide él: lo deciden ROLE_PARTNER y el cortafuegos, que van
     * al lado. Negar aquí no cambiaría nada; lo que importa es que no rompa.
     */
    public function testSinSesionNoRompe(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            (new MemberVoter())->vote(new NullToken(), null, [MemberVoter::MEMBER_AREA])
        );
    }

    public function testNoOpinaDeOtrosAtributos(): void
    {
        $user = (new User())->setPartner((new Partner())->setMember(false));
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, (new MemberVoter())->vote($token, null, ['ROLE_PARTNER']));
    }
}
