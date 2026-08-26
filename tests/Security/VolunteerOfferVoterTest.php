<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Security\VolunteerOfferVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Quién puede tocar qué tarea de voluntariado.
 *
 * La regla que se fija aquí es la que no cabe en un rol: quien coordina el
 * reparto de los viernes puede con SUS tareas y con ninguna más. Si esto se
 * relajara, nombrar coordinadora a una persona para un área le daría el módulo
 * entero, que es justo lo que se quería evitar.
 */
class VolunteerOfferVoterTest extends TestCase
{
    /**
     * Quien lleva el voluntariado entero puede con cualquier tarea, coordine o
     * no esa área.
     */
    public function testElRolGlobalDeEscrituraPuedeConTodo(): void
    {
        $offer = $this->offerOfArea($this->category('Huerta'));

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($offer, new User(), ['ROLE_GESTION_VOLUNTARIADO_EDIT'], VolunteerOfferVoter::EDIT)
        );
    }

    /**
     * Quien coordina el área puede con su tarea sin tener el rol global de
     * escritura. Es el caso que motiva todo esto: quien organiza el reparto de
     * los viernes cierra sus propias tareas.
     */
    public function testQuienCoordinaElAreaPuedeConSuTarea(): void
    {
        $marta = new User();
        $reparto = $this->category('Reparto')->addCoordinator($marta);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->offerOfArea($reparto), $marta, ['ROLE_GESTION_VOLUNTARIADO'], VolunteerOfferVoter::EDIT)
        );
    }

    /**
     * Y NO puede con la de otra área. Es la mitad importante de la regla: sin
     * esto, nombrar a alguien coordinadora de un área le daría el módulo entero.
     */
    public function testQuienCoordinaUnAreaNoPuedeConLaDeOtra(): void
    {
        $marta = new User();
        $this->category('Reparto')->addCoordinator($marta);
        $huerta = $this->category('Huerta');

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->offerOfArea($huerta), $marta, ['ROLE_GESTION_VOLUNTARIADO'], VolunteerOfferVoter::EDIT)
        );
    }

    /**
     * Aunque sí puede VERLA: la lectura alcanza a todas las áreas, para que se
     * pueda saber cómo va el conjunto sin poder tocar lo ajeno.
     */
    public function testQuienCoordinaUnAreaSiPuedeVerLaDeOtra(): void
    {
        $marta = new User();
        $this->category('Reparto')->addCoordinator($marta);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->offerOfArea($this->category('Huerta')), $marta, ['ROLE_GESTION_VOLUNTARIADO'], VolunteerOfferVoter::VIEW)
        );
    }

    /**
     * Una tarea SIN área sólo la toca quien tiene el rol global, y no es un
     * descuido: sin categoría no hay área, así que no hay nadie de quien se
     * pueda decir que es "su" tarea. Lo contrario haría que crear una tarea sin
     * marcar su tipo abriera la puerta a todas las coordinadoras.
     */
    public function testUnaTareaSinAreaSoloLaTocaElRolGlobal(): void
    {
        $marta = new User();
        $this->category('Reparto')->addCoordinator($marta);

        $offer = (new VolunteerOffer())->setTitle('Tarea suelta');

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($offer, $marta, ['ROLE_GESTION_VOLUNTARIADO'], VolunteerOfferVoter::EDIT)
        );
        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($offer, new User(), ['ROLE_GESTION_VOLUNTARIADO_EDIT'], VolunteerOfferVoter::EDIT)
        );
    }

    /**
     * Sin ningún rol de voluntariado no se ve nada, ni siquiera coordinando —
     * caso imposible en la práctica porque el rol se deriva de la coordinación,
     * pero el voter no debe depender de que esa derivación exista.
     */
    public function testSinRolDeVoluntariadoNoSeVeNada(): void
    {
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->offerOfArea($this->category('Huerta')), new User(), [], VolunteerOfferVoter::VIEW)
        );
    }

    /**
     * @param string $name nombre del área
     */
    private function category(string $name): VolunteerCategory
    {
        return (new VolunteerCategory())->setName($name);
    }

    /**
     * @param VolunteerCategory $category el área de la tarea
     */
    private function offerOfArea(VolunteerCategory $category): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->addCategory($category);
    }

    /**
     * Vota, con un decisor que concede exactamente los roles indicados.
     *
     * @param VolunteerOffer $offer     la tarea
     * @param User           $user      quien pregunta
     * @param list<string>   $roles     los roles que se le conceden
     * @param string         $attribute VIEW o EDIT
     *
     * @return int el resultado del voter
     */
    private function vote(VolunteerOffer $offer, User $user, array $roles, string $attribute): int
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $decisionManager = $this->createMock(AccessDecisionManagerInterface::class);
        $decisionManager->method('decide')->willReturnCallback(
            static fn (TokenInterface $t, array $attributes): bool => \in_array($attributes[0], $roles, true)
        );

        return (new VolunteerOfferVoter($decisionManager))->vote($token, $offer, [$attribute]);
    }
}
