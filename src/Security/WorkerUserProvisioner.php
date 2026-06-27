<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Worker;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Da y retira el acceso de login de un {@see Worker} (trabajador), iniciado por
 * la administración desde su ficha.
 *
 * La vinculación es POR EMAIL y resuelve los dos casos reales con una sola vía:
 *   - El trabajador YA tiene cuenta (p. ej. es también socix y entra con su
 *     email): se le AÑADE la faceta laboral a esa misma cuenta, no se crea otra.
 *   - El trabajador no tiene cuenta (p. ej. no es socix): se CREA una nueva con
 *     ese email, con contraseña placeholder y passwordSet = false (al primer
 *     acceso, por magic-link, configurará la suya).
 *
 * No toca la columna `roles`: ROLE_WORKER se deriva de tener un Worker vinculado
 * ({@see User::getRoles()}). El envío del enlace de acceso lo hace el controller
 * con {@see MagicLinkMailer}.
 */
class WorkerUserProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Cuenta de login vinculada a este trabajador, o null si aún no tiene acceso.
     *
     * @param Worker $worker
     * @return User|null
     */
    public function userFor(Worker $worker): ?User
    {
        return $this->userRepository->findOneBy(['worker' => $worker]);
    }

    /**
     * Concede acceso al trabajador por email: reutiliza la cuenta existente con
     * ese email (añadiéndole la faceta laboral) o crea una nueva. Devuelve el
     * User resultante, listo para enviarle el enlace de acceso.
     *
     * @param Worker $worker Trabajador al que dar acceso.
     * @param string $email  Email de acceso (se normaliza a minúsculas).
     * @return User
     * @throws \LogicException Si el email no es válido o ya está vinculado a otro trabajador.
     */
    public function grantAccess(Worker $worker, string $email): User
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \LogicException('Introduce un email válido para dar acceso.');
        }

        $existing = $this->userRepository->loadUserByIdentifier($email);
        if ($existing instanceof User) {
            $other = $existing->getWorker();
            if ($other !== null && $other->getId() !== $worker->getId()) {
                throw new \LogicException('Ese email ya está vinculado a otro trabajador.');
            }
            $existing->setWorker($worker);
            $this->em->flush();

            return $existing;
        }

        $user = new User();
        $user->setUsername($email);
        $user->setEmail($email);
        $user->setWorker($worker);
        $user->setPasswordSet(false);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Retira el acceso laboral: desvincula el Worker de su cuenta. Si la cuenta
     * era SOLO laboral (sin socix ni rol de equipo) queda huérfana y se
     * deshabilita para que no quede un login activo sin función; si además es
     * socix o del equipo, la cuenta sigue viva, solo pierde la faceta de fichar.
     *
     * @param Worker $worker
     * @return void
     */
    public function revokeAccess(Worker $worker): void
    {
        $user = $this->userFor($worker);
        if ($user === null) {
            return;
        }

        $user->setWorker(null);

        // Tras quitar el Worker, getRoles() ya no incluye ROLE_WORKER. Si lo
        // único que queda es ROLE_USER y no hay socix, la cuenta no sirve para
        // nada: se deshabilita en vez de dejar un acceso fantasma.
        $leftover = array_diff($user->getRoles(), ['ROLE_USER']);
        if ($user->getPartner() === null && $leftover === []) {
            $user->setEnabled(false);
        }

        $this->em->flush();
    }

    /**
     * Libera la cuenta de login al BORRAR un trabajador (no al retirarle el
     * acceso). A diferencia de {@see WorkerUserProvisioner::revokeAccess()}, que
     * deshabilita la cuenta huérfana para conservarla, aquí el trabajador
     * desaparece por completo: una cuenta que SOLO servía para fichar (sin socix
     * ni roles de equipo) se BORRA, para no dejar un login muerto. Si la cuenta
     * es compartida (también socix o del equipo) solo se le quita la faceta
     * laboral y sigue viva.
     *
     * No hace flush: forma parte del borrado del Worker, que el controller
     * confirma en una sola transacción.
     *
     * @param Worker $worker Trabajador que se está borrando.
     * @return void
     */
    public function releaseUserOnWorkerDeletion(Worker $worker): void
    {
        $user = $this->userFor($worker);
        if ($user === null) {
            return;
        }

        $user->setWorker(null);

        $leftover = array_diff($user->getRoles(), ['ROLE_USER']);
        if ($user->getPartner() === null && $leftover === []) {
            $this->em->remove($user);
        }
    }
}
