<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Carga del User para el firewall. Búsqueda case-insensitive contra
     * username_canonical (campo lowercased que se mantiene en sync por
     * User::setUsername). Permite que "Admin", "ADMIN" y "admin" sean
     * el mismo login, y para socixs creados via primer acceso que el
     * email funcione igual independientemente de cómo lo escriban.
     *
     * También intenta por email_canonical: como en el flujo de primer
     * acceso el username de un socix es su email, ambos campos suelen
     * coincidir, pero para usuarias antiguas (admin) el username y el
     * email son distintos — caer al email permite que un admin pueda
     * acceder usando su email si se acuerda más de eso que del alias.
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        $canonical = mb_strtolower($identifier);

        return $this->createQueryBuilder('u')
            ->where('u.usernameCanonical = :id OR u.emailCanonical = :id')
            ->setParameter('id', $canonical)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByRole($role)
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :roles')
            ->setParameter('roles', '%"' . $role . '"%')
            ->getQuery()
            ->getResult();
    }
}
