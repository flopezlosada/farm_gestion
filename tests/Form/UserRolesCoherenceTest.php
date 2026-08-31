<?php

namespace App\Tests\Form;

use App\Form\UserType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Blinda la coherencia entre los dos sitios donde viven los roles de gestión:
 * la jerarquía de seguridad ({@see config/packages/security.yaml}) y el catálogo
 * de secciones asignables desde el formulario de usuarias
 * ({@see UserType::SECTIONS}).
 *
 * Al añadir un módulo con roles nuevos es fácil declararlos en un sitio y
 * olvidar el otro; sin este test, el síntoma solo aparece cuando no se puede
 * asignar el rol a nadie (le pasó al LAR y al módulo laboral). Aquí salta en CI.
 */
class UserRolesCoherenceTest extends TestCase
{
    /**
     * Todo rol de lectura/edición ofrecido en el formulario debe existir de
     * verdad en la jerarquía de security.yaml (no ofrecer roles fantasma).
     */
    public function testSectionRolesExistInSecurityHierarchy(): void
    {
        $known = $this->hierarchyRoles();

        foreach (UserType::SECTIONS as $key => $section) {
            $this->assertArrayHasKey(
                $section['read'],
                $known,
                sprintf('El rol de lectura de la sección "%s" (%s) no está en security.yaml.', $key, $section['read'])
            );
            if ($section['edit'] !== null) {
                $this->assertArrayHasKey(
                    $section['edit'],
                    $known,
                    sprintf('El rol de edición de la sección "%s" (%s) no está en security.yaml.', $key, $section['edit'])
                );
            }
        }
    }

    /**
     * Todo rol de gestión definido en security.yaml (ROLE_GESTION_* de lectura y
     * ROLE_BLOG) debe ser asignable desde el formulario de usuarias, o quedará
     * como un rol imposible de dar a nadie salvo al admin que lo hereda.
     */
    public function testEveryGestionRoleIsAssignableInUserForm(): void
    {
        $readRoles = array_column(UserType::SECTIONS, 'read');

        foreach (array_keys($this->hierarchyRoles()) as $role) {
            $isSectionRole = $role === 'ROLE_BLOG'
                || (str_starts_with($role, 'ROLE_GESTION_') && !str_ends_with($role, '_EDIT'));

            if ($isSectionRole) {
                $this->assertContains(
                    $role,
                    $readRoles,
                    sprintf('El rol de gestión "%s" existe en security.yaml pero no es asignable en UserType::SECTIONS (falta su sección en el formulario de usuarias).', $role)
                );
            }
        }
    }

    /**
     * Todos los roles nombrados en la jerarquía de security.yaml (claves y
     * valores), como conjunto.
     *
     * @return array<string, true>
     */
    private function hierarchyRoles(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/security.yaml');
        $hierarchy = $config['security']['role_hierarchy'] ?? [];

        $known = [];
        foreach ($hierarchy as $role => $children) {
            $known[$role] = true;
            foreach ((array) $children as $child) {
                $known[$child] = true;
            }
        }

        return $known;
    }
}
