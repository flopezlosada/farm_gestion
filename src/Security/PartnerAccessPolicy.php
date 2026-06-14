<?php

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AppSettings;

/**
 * Responde a "¿este socix puede entrar (o llegar a entrar) a la web?".
 *
 * Es la única pieza que conoce las dos condiciones a la vez: si el socix ya
 * tiene cuenta utilizable, y si el alta de usuarixs nuevxs está abierta
 * ({@see AppSettings::SELF_REGISTRATION}). La usan el provisioner (gate de
 * creación), la ficha de admin (estado + botón "dar acceso") y los emails
 * (decidir si pintar enlaces de acción que exigen login).
 */
class PartnerAccessPolicy
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Email canónico del Partner para efectos de login: trim + minúsculas.
     * Los logins son case-insensitive, así que toda búsqueda o creación de
     * User pasa por aquí.
     *
     * @return string|null null si el Partner no tiene email (sin email no hay acceso).
     */
    public function canonicalEmailOf(Partner $partner): ?string
    {
        $raw = $partner->getEmail();
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return mb_strtolower(trim($raw));
    }

    /**
     * Cuenta de acceso utilizable por este socix, si existe: el User cuyo
     * identifier es su email canónico. Cubre también el caso de familia que
     * comparte buzón (User vinculado a otro Partner pero con el mismo email).
     */
    public function accountFor(Partner $partner): ?User
    {
        $email = $this->canonicalEmailOf($partner);
        if ($email === null) {
            return null;
        }

        $user = $this->userRepository->loadUserByIdentifier($email);

        return $user instanceof User ? $user : null;
    }

    /**
     * ¿Está abierta el alta de usuarixs nuevxs? (toggle de configuración).
     */
    public function isSelfRegistrationOpen(): bool
    {
        return $this->settings->getBool(AppSettings::SELF_REGISTRATION);
    }

    /**
     * ¿Tiene sentido ofrecerle a este socix enlaces que exigen login (panel,
     * "no recojo esta semana"…)? Sí cuando ya tiene cuenta habilitada, o
     * cuando podría creársela él mismo (alta abierta y tiene email).
     */
    public function canUseActionLinks(Partner $partner): bool
    {
        $account = $this->accountFor($partner);
        if ($account !== null) {
            return $account->isEnabled();
        }

        return $this->isSelfRegistrationOpen() && $this->canonicalEmailOf($partner) !== null;
    }
}
