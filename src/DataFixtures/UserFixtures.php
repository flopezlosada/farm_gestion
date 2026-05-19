<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crea el usuario administrador para el entorno de desarrollo.
 *
 * Sin esto, cada `doctrine:fixtures:load` deja la BBDD sin acceso porque
 * la tabla fos_user está mapeada y se purga junto al resto.
 */
class UserFixtures extends Fixture
{
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_EMAIL = 'admin@csavega.local';
    private const ADMIN_PASSWORD = 'admin';

    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setUsername(self::ADMIN_USERNAME);
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setPassword($this->hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $admin->setPasswordSet(true);
        $admin->setEnabled(true);
        $admin->setRoles(['ROLE_ADMIN']);

        $manager->persist($admin);
        $manager->flush();
    }
}
