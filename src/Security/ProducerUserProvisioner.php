<?php

namespace App\Security;

use App\Entity\Producer;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Da y retira el acceso de login de un {@see Producer} autogestionado, iniciado
 * por la comisión desde su ficha. Mismo patrón que {@see WorkerUserProvisioner}.
 *
 * A diferencia de Worker (email por input del formulario), el email lo trae el
 * propio {@see Producer} — es un dato de su ficha, no algo que se teclee aparte.
 *
 * La vinculación es POR EMAIL: si ya existe una cuenta con ese correo (por
 * ejemplo, el productor también es socix) se le añade la faceta de productor a
 * esa misma cuenta; si no, se crea una nueva con contraseña placeholder y
 * `passwordSet = false`, para que el primer acceso sea por magic-link.
 */
class ProducerUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Cuenta de login vinculada a este productor, o null si aún no tiene acceso.
     */
    public function userFor(Producer $producer): ?User
    {
        return $this->userRepository->findOneBy(['producer' => $producer]);
    }

    /**
     * Concede acceso al productor usando el email de su ficha. Devuelve el User
     * resultante, listo para enviarle el enlace de acceso.
     *
     * @throws \LogicException Si el productor no tiene email, o si ese email ya
     *                          está vinculado a OTRO productor.
     */
    public function grantAccess(Producer $producer): User
    {
        $email = mb_strtolower(trim((string) $producer->getEmail()));
        if ($email === '') {
            throw new \LogicException('Este productor no tiene email en su ficha: añádelo antes de darle acceso.');
        }

        $existing = $this->userRepository->loadUserByIdentifier($email);
        if ($existing instanceof User) {
            $other = $existing->getProducer();
            if ($other !== null && $other->getId() !== $producer->getId()) {
                throw new \LogicException('Ese email ya está vinculado a otro productor.');
            }
            $existing->setProducer($producer);
            $this->em->flush();

            return $existing;
        }

        $user = new User();
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setProducer($producer);
        $user->setPasswordSet(false);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Retira el acceso: desvincula el Producer de su cuenta. Si la cuenta era
     * SOLO de productor (sin socix ni trabajador ni roles de equipo), queda
     * huérfana y se deshabilita para no dejar un login activo sin función.
     */
    public function revokeAccess(Producer $producer): void
    {
        $user = $this->userFor($producer);
        if ($user === null) {
            return;
        }

        $user->setProducer(null);

        $leftover = array_diff($user->getRoles(), ['ROLE_USER']);
        if ($user->getPartner() === null && $user->getWorker() === null && $leftover === []) {
            $user->setEnabled(false);
        }

        $this->em->flush();
    }
}
