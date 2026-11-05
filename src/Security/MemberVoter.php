<?php

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `MEMBER_AREA`: las partes del panel que son de socias (encuestas, grupo de
 * consumo, voluntariado). Quien recibe cesta sin ser socia —una trabajadora de la
 * asociación— tiene ficha y por tanto ROLE_PARTNER, pero a esas partes no entra.
 *
 * NIEGA SÓLO ESE CASO, y es a propósito: una ficha marcada como no socia. Todo lo
 * demás (socias, admins sin ficha, cuentas con el rol guardado a mano) queda como
 * estaba, porque esta comprobación va junto a la que ya hubiera (ROLE_PARTNER y el
 * feature-flag), no en su lugar. Un rol «socia» derivado obligaría a reescribir
 * esas comprobaciones y cualquier cuenta que hoy entra por otro camino se quedaría
 * fuera.
 */
class MemberVoter extends Voter
{
    /** Atributo para las áreas del panel reservadas a socias. */
    public const MEMBER_AREA = 'MEMBER_AREA';

    /**
     * {@inheritdoc}
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MEMBER_AREA === $attribute;
    }

    /**
     * {@inheritdoc}
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $partner = $user instanceof User ? $user->getPartner() : null;

        return !$partner instanceof Partner || $partner->isMember();
    }
}
