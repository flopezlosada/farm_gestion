<?php

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Provisiona (o recupera) la cuenta de acceso de un socix.
 *
 * Lógica compartida por el primer acceso del magic-link y el SSO con Google:
 * ambos acaban necesitando el User vinculado a un Partner para emitir el login.
 * El magic-link ya identifica al Partner (email + teléfono) y llama a
 * {@see resolveOrCreate()}; el SSO sólo tiene un email verificado y entra por
 * {@see resolveByVerifiedEmail()}, que aplica la política "exactamente 1 socix".
 *
 * La CREACIÓN de cuentas nuevas por estas dos vías está condicionada al toggle
 * de configuración "alta abierta" ({@see PartnerAccessPolicy::isSelfRegistrationOpen()}):
 * con el alta cerrada, las cuentas existentes siguen resolviéndose pero no se
 * crea ninguna nueva. La administración puede saltarse el toggle para un socix
 * concreto vía {@see provision()} (botón "dar acceso" de la ficha).
 */
class PartnerUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PartnerRepository $partnerRepository,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resuelve el User de acceso a partir de un email YA verificado por un
     * proveedor de identidad (el SSO de Google).
     *
     * Política de provisioning del SSO: sólo se loguea si el email casa con
     * EXACTAMENTE un Partner activo. 0 candidatos ⇒ el email no pertenece a
     * ningún socix activo ⇒ null (no se autoprovisiona ninguna cuenta a lo
     * loco). >1 candidatos ⇒ ambiguo (familia que comparte buzón) ⇒ null +
     * warning para resolución manual; nunca se adivina cuál es.
     *
     * El User resuelto se marca con passwordSet = true: el SSO de Google ya es
     * una vía de acceso permanente, así que no tiene sentido que el panel le
     * fuerce la pantalla de "configura tu contraseña" (eso es propio del
     * magic-link, donde el enlace es de un solo uso). Si la quiere, el socix
     * puede ponérsela luego desde su perfil.
     *
     * @param string $verifiedEmail email cuya propiedad garantiza el proveedor
     *
     * @return User|null el User logueable, o null si no hay un único candidato
     */
    public function resolveByVerifiedEmail(string $verifiedEmail): ?User
    {
        $candidates = $this->partnerRepository->findActiveByEmail($verifiedEmail);

        if (count($candidates) > 1) {
            $this->logger->warning(
                'SSO: el email verificado casa con >1 socix activo; login rechazado para resolución manual.',
                [
                    'email' => $verifiedEmail,
                    'partner_ids' => array_map(static fn (Partner $p): ?int => $p->getId(), $candidates),
                ]
            );

            return null;
        }

        if (0 === count($candidates)) {
            return null;
        }

        $user = $this->resolveOrCreate($candidates[0]);

        if ($user !== null && !$user->isPasswordSet()) {
            $user->setPasswordSet(true);
            $this->em->flush();
        }

        return $user;
    }

    /**
     * Devuelve el User vinculado al Partner, creándolo si no existe Y el alta
     * de usuarixs nuevxs está abierta. Con el alta cerrada las cuentas
     * existentes se siguen resolviendo (los pilotos provisionados por admin
     * entran con normalidad), pero no se crea ninguna nueva.
     *
     * Si el Partner no tiene email no se puede provisionar (el login va por
     * correo) y se devuelve null. Si ya existe un User con ese email pero NO
     * está vinculado a este Partner (familia que comparte buzón), se reutiliza
     * el existente; la asociación gestiona esos casos a mano.
     *
     * @param Partner $partner socix ya identificado para el que se quiere el acceso
     *
     * @return User|null el User vinculado, o null si no hay email o el alta está cerrada
     */
    public function resolveOrCreate(Partner $partner): ?User
    {
        $email = $this->accessPolicy->canonicalEmailOf($partner);
        if ($email === null) {
            return null;
        }

        $existing = $this->accessPolicy->accountFor($partner);
        if ($existing !== null) {
            return $existing;
        }

        if (!$this->accessPolicy->isSelfRegistrationOpen()) {
            $this->logger->info(
                'Provisioning rechazado: el alta de usuarixs nuevxs está cerrada por configuración.',
                ['partner_id' => $partner->getId()]
            );

            return null;
        }

        return $this->createUser($partner, $email);
    }

    /**
     * Provisioning INCONDICIONAL, iniciado por la administración (botón "dar
     * acceso" de la ficha): crea o recupera la cuenta saltándose el toggle de
     * alta abierta y le concede acceso anticipado, de modo que el socix pueda
     * entrar aunque el grifo global de acceso de socixs siga cerrado. Es el
     * mecanismo de despliegue selectivo (autorizar uno a uno) previo a abrir.
     *
     * La marca de acceso anticipado se asegura también cuando la cuenta ya
     * existía: el botón significa "este socix puede entrar ya", con cuenta
     * nueva o vieja.
     *
     * @param Partner $partner socix al que admin quiere dar acceso
     *
     * @return User|null el User vinculado, o null si el Partner no tiene email
     */
    public function provision(Partner $partner): ?User
    {
        $email = $this->accessPolicy->canonicalEmailOf($partner);
        if ($email === null) {
            return null;
        }

        $user = $this->accessPolicy->accountFor($partner) ?? $this->createUser($partner, $email);

        if (!$user->isEarlyAccess()) {
            $user->setEarlyAccess(true);
            $this->em->flush();
        }

        return $user;
    }

    /**
     * Crea y persiste el User de un Partner: username = email canónico,
     * password aleatorio (placeholder) y passwordSet = false, de modo que la
     * primera vez que entre, el panel le fuerce a configurar su contraseña real.
     *
     * @param Partner $partner socix dueño de la cuenta
     * @param string  $email   email ya canonicalizado (lowercase, sin espacios)
     */
    private function createUser(Partner $partner, string $email): User
    {
        $user = new User();
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setRoles(['ROLE_PARTNER']);
        $user->setPartner($partner);
        $user->setPasswordSet(false);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
