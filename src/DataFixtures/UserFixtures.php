<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use FOS\UserBundle\Model\UserManagerInterface;

/**
 * Crea el usuario administrador para el entorno de desarrollo.
 *
 * Sin esto, cada `doctrine:fixtures:load` deja la BBDD sin acceso porque
 * la tabla fos_user está mapeada y se purga junto al resto.
 *
 * Cuando se reescriba la auth con seguridad nativa de Symfony (Fase 6),
 * este fixture se sustituye por uno propio.
 */
class UserFixtures extends Fixture
{
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_EMAIL = 'admin@csavega.local';
    private const ADMIN_PASSWORD = 'admin';

    private UserManagerInterface $userManager;

    /**
     * Inyecta el UserManager de FOSUser para que el hashing de la password
     * y la creación del usuario se hagan a través del flujo oficial del bundle.
     *
     * @param UserManagerInterface $userManager
     */
    public function __construct(UserManagerInterface $userManager)
    {
        $this->userManager = $userManager;
    }

    /**
     * Crea el usuario admin con rol ROLE_SUPER_ADMIN.
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $admin = $this->userManager->createUser();
        $admin->setUsername(self::ADMIN_USERNAME);
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setPlainPassword(self::ADMIN_PASSWORD);
        $admin->setEnabled(true);
        $admin->setSuperAdmin(true);

        $this->userManager->updateUser($admin, true);
    }
}
