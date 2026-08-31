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
     * Las cuentas de acceso de estxs socixs, en UNA consulta.
     *
     * Hace falta porque el vínculo vive en el lado User ({@see User::$partner},
     * un OneToOne sin lado inverso): desde un Partner no se puede navegar a su
     * User, así que hay que preguntar al revés.
     *
     * Devuelve menos cuentas que socixs se le pasen, y no es un error: quien
     * todavía no tiene acceso a la web no aparece. Un aviso push necesita una
     * sesión detrás, y esa gente se entera por otros medios.
     *
     * @param list<\App\Entity\Partner> $partners lxs socixs
     *
     * @return list<User> sus cuentas de acceso
     */
    public function findByPartners(array $partners): array
    {
        if ([] === $partners) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.partner IN (:partners)')
            ->setParameter('partners', $partners)
            ->getQuery()
            ->getResult();
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

    /**
     * Todas las cuentas que pueden entrar. Punto de partida de "quién tiene tal
     * permiso", que se resuelve DESPUÉS en PHP con el servicio de jerarquía de
     * Symfony.
     *
     * SE TRAEN TODAS Y SE FILTRA FUERA, y no es pereza: no sirve
     * {@see findByRole()} —un LIKE sobre el array serializado, que sólo encuentra
     * el rol LITERAL—, porque quien coordina socixs suele tener ROLE_ADMIN, y de
     * ahí a ROLE_GESTION_SOCIXS hay dos saltos de jerarquía
     * (ROLE_ADMIN → ROLE_GESTION_SOCIXS_EDIT → ROLE_GESTION_SOCIXS). Buscando el
     * literal, el aviso no le llegaría a nadie y nadie sabría por qué. Y la
     * jerarquía vive en `security.yaml`, no en la base, así que en DQL no se puede
     * resolver.
     *
     * Sin filtro por roles vacíos, que era la optimización evidente y no lo es:
     * de las cuarenta y tres cuentas reales sólo dos llevan el array vacío —las
     * demás tienen ROLE_PARTNER escrito—, así que ahorraría dos filas a cambio de
     * una condición contra el formato serializado de PHP metida en una consulta.
     *
     * @return User[] las cuentas habilitadas
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.enabled = true')
            ->getQuery()
            ->getResult();
    }
}
