<?php

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
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
 */
class PartnerUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $userRepository,
        private readonly PartnerRepository $partnerRepository,
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

        return $this->resolveOrCreate($candidates[0]);
    }

    /**
     * Devuelve el User vinculado al Partner, creándolo si no existe.
     *
     * Si el Partner no tiene email no se puede provisionar (el login va por
     * correo) y se devuelve null. Si ya existe un User con ese email pero NO
     * está vinculado a este Partner (familia que comparte buzón), se reutiliza
     * el existente; la asociación gestiona esos casos a mano.
     *
     * El User nuevo se crea con username = email, password aleatorio
     * (placeholder) y passwordSet = false: la primera vez que entre, el panel
     * le forzará a configurar su contraseña real.
     *
     * @param Partner $partner socix ya identificado para el que se quiere el acceso
     *
     * @return User|null el User vinculado, o null si el Partner no tiene email
     */
    public function resolveOrCreate(Partner $partner): ?User
    {
        $rawEmail = $partner->getEmail();
        if ($rawEmail === null || $rawEmail === '') {
            return null;
        }

        // Canonicalizamos a lowercase: los logins son case-insensitive (vía
        // UserRepository::loadUserByIdentifier) y los identificadores visibles
        // quedan más limpios.
        $email = mb_strtolower(trim($rawEmail));

        $existing = $this->userRepository->loadUserByIdentifier($email);
        if ($existing instanceof User) {
            return $existing;
        }

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
