<?php

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\UserType;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Tests del mapeo nivel-de-acceso ↔ roles de {@see UserType}. Es lógica
 * propia (listeners PRE_SET_DATA/POST_SUBMIT) y sensible (decide permisos),
 * así que se prueba aislada del HTTP/CSRF/BBDD con el form factory de test.
 *
 * Se usa include_partner=false para no arrastrar el EntityType de Partner
 * (necesitaría el registry de Doctrine) y require_password=false para no
 * meter el campo de contraseña; ninguno afecta al mapeo de roles.
 */
class UserTypeTest extends TypeTestCase
{
    /** Datos base del form sin ningún acceso marcado. */
    private function baseSubmit(array $overrides = []): array
    {
        return array_merge([
            'username' => 'tester',
            'email' => 'tester@example.test',
            'is_admin' => false,
            'access_granja' => UserType::LEVEL_NONE,
            'access_socixs' => UserType::LEVEL_NONE,
            'access_reparto' => UserType::LEVEL_NONE,
            'access_blog' => UserType::LEVEL_NONE,
            'access_encuestas' => UserType::LEVEL_NONE,
            'access_albergue' => UserType::LEVEL_NONE,
        ], $overrides);
    }

    private function submit(array $overrides = []): User
    {
        $user = new User();
        $form = $this->factory->create(UserType::class, $user, [
            'require_password' => false,
            'include_partner' => false,
        ]);
        $form->submit($this->baseSubmit($overrides));

        $this->assertTrue($form->isSynchronized());

        return $user;
    }

    /**
     * Nivel "edición" en una sección partida → guarda el rol _EDIT a secas
     * (la lectura le llega por jerarquía, no se duplica en la columna).
     */
    public function testAlbergueEditLevelStoresEditRoleOnly(): void
    {
        $roles = $this->submit(['access_albergue' => UserType::LEVEL_EDIT])->getRoles();

        $this->assertContains('ROLE_GESTION_ALBERGUE_EDIT', $roles);
        $this->assertNotContains('ROLE_GESTION_ALBERGUE', $roles);
    }

    /** Encuestas también tiene el split: edición → rol _EDIT a secas. */
    public function testEncuestasEditLevelStoresEditRoleOnly(): void
    {
        $roles = $this->submit(['access_encuestas' => UserType::LEVEL_EDIT])->getRoles();

        $this->assertContains('ROLE_GESTION_ENCUESTAS_EDIT', $roles);
        $this->assertNotContains('ROLE_GESTION_ENCUESTAS', $roles);
    }

    /** Socixs también tiene el split (piloto): edición → rol _EDIT a secas. */
    public function testSocixsEditLevelStoresEditRoleOnly(): void
    {
        $roles = $this->submit(['access_socixs' => UserType::LEVEL_EDIT])->getRoles();

        $this->assertContains('ROLE_GESTION_SOCIXS_EDIT', $roles);
        $this->assertNotContains('ROLE_GESTION_SOCIXS', $roles);
    }

    /** Socixs lectura → guarda solo el rol base (sin escritura). */
    public function testSocixsReadLevelStoresReadRole(): void
    {
        $roles = $this->submit(['access_socixs' => UserType::LEVEL_READ])->getRoles();

        $this->assertContains('ROLE_GESTION_SOCIXS', $roles);
        $this->assertNotContains('ROLE_GESTION_SOCIXS_EDIT', $roles);
    }

    /** Nivel "lectura" en una sección partida → guarda el rol base. */
    public function testAlbergueReadLevelStoresReadRole(): void
    {
        $roles = $this->submit(['access_albergue' => UserType::LEVEL_READ])->getRoles();

        $this->assertContains('ROLE_GESTION_ALBERGUE', $roles);
        $this->assertNotContains('ROLE_GESTION_ALBERGUE_EDIT', $roles);
    }

    /**
     * Sección sin split (granja): "acceso completo" usa el nivel EDICIÓN (mismo
     * color verde) y guarda el rol único de la sección.
     */
    public function testNonSplitSectionStoresBaseRole(): void
    {
        $roles = $this->submit(['access_granja' => UserType::LEVEL_EDIT])->getRoles();

        $this->assertContains('ROLE_GESTION_GRANJA', $roles);
    }

    /** Admin marcado manda: ignora los selectores de sección. */
    public function testAdminOverridesSections(): void
    {
        $roles = $this->submit([
            'is_admin' => true,
            'access_albergue' => UserType::LEVEL_EDIT,
        ])->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertNotContains('ROLE_GESTION_ALBERGUE_EDIT', $roles);
    }

    /**
     * Al editar, los roles que el form NO gestiona (p.ej. ROLE_SUPER_ADMIN) se
     * preservan; no se borran al recomponer el array de roles.
     */
    public function testPreservesRolesOutsideSections(): void
    {
        $user = (new User())->setRoles(['ROLE_GESTION_GRANJA', 'ROLE_SUPER_ADMIN']);
        $form = $this->factory->create(UserType::class, $user, [
            'require_password' => false,
            'include_partner' => false,
        ]);
        // Edición sin tocar nada: granja sigue en acceso completo (lo precarga
        // PRE_SET_DATA en EDIT), y ROLE_SUPER_ADMIN debe sobrevivir.
        $form->submit($this->baseSubmit(['access_granja' => UserType::LEVEL_EDIT]));
        $this->assertTrue($form->isSynchronized());

        $roles = $user->getRoles();
        $this->assertContains('ROLE_GESTION_GRANJA', $roles);
        $this->assertContains('ROLE_SUPER_ADMIN', $roles);
    }

    /** Sin nada marcado, el usuario se queda solo con ROLE_USER. */
    public function testNoAccessLeavesOnlyUserRole(): void
    {
        $this->assertSame(['ROLE_USER'], $this->submit()->getRoles());
    }

    /**
     * Precarga (edición): un User con ROLE_GESTION_ALBERGUE_EDIT abre el form
     * con el selector del albergue en "edición".
     */
    public function testPreSetDataDerivesEditLevel(): void
    {
        $user = (new User())->setRoles(['ROLE_GESTION_ALBERGUE_EDIT']);
        $form = $this->factory->create(UserType::class, $user, [
            'require_password' => false,
            'include_partner' => false,
        ]);

        $this->assertSame(UserType::LEVEL_EDIT, $form->get('access_albergue')->getData());
        $this->assertFalse($form->get('is_admin')->getData());

        // Y la VISTA debe marcar el radio correcto: el bug era que getData()
        // devolvía bien el nivel pero el radio salía sin `checked`.
        $albergue = $form->createView()->children['access_albergue'];
        $checked = array_values(array_filter($albergue->children, fn ($opt) => $opt->vars['checked']));
        $this->assertCount(1, $checked, 'Debe haber exactamente un radio marcado.');
        $this->assertSame(UserType::LEVEL_EDIT, $checked[0]->vars['value']);
    }
}
