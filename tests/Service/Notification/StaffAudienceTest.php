<?php

namespace App\Tests\Service\Notification;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Notification\StaffAudience;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Quién recibe los avisos internos.
 *
 * Se prueba con la jerarquía REAL de la aplicación y no con un doble, porque lo
 * único que hay que demostrar aquí es justo lo que la forma ingenua se salta:
 * casi nadie tiene escrito el permiso por el que se le avisa, lo alcanza desde
 * ROLE_ADMIN a través de varios saltos. Un LIKE sobre la columna no encontraría
 * a nadie y la tarea seguiría saliendo en verde sin avisar a nadie.
 */
class StaffAudienceTest extends TestCase
{
    /** Un trozo fiel de la jerarquía de security.yaml. */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_GESTION_SOCIXS_EDIT'],
        'ROLE_GESTION_SOCIXS_EDIT' => ['ROLE_GESTION_SOCIXS'],
    ];

    /** Quien tiene el rol escrito, evidentemente, entra. */
    public function testEncuentraAQuienTieneElRolLiteral(): void
    {
        $admin = $this->user('admin@test.org', ['ROLE_ADMIN']);

        $audience = $this->audience([$admin, $this->user('otra@test.org', ['ROLE_PARTNER'])]);

        $this->assertSame(['admin@test.org'], $audience->emailsWithRole('ROLE_ADMIN'));
    }

    /**
     * EL CASO QUE IMPORTA: quien administra alcanza permisos que no tiene
     * escritos. Buscando el literal, estos avisos no le llegarían a nadie.
     */
    public function testEncuentraAQuienAlcanzaElRolPorJerarquia(): void
    {
        $audience = $this->audience([$this->user('admin@test.org', ['ROLE_ADMIN'])]);

        $this->assertSame(
            ['admin@test.org'],
            $audience->emailsWithRole('ROLE_GESTION_SOCIXS'),
            'ROLE_ADMIN llega a ROLE_GESTION_SOCIXS por jerarquía, aunque no lo tenga escrito.'
        );
    }

    /**
     * Sin correo no hay a quién escribir, y una dirección vacía colada en el
     * destinatario tumbaría el envío entero.
     */
    public function testDescartaLasCuentasSinCorreoYLosDuplicados(): void
    {
        $sinCorreo = new User();
        $sinCorreo->setUsername('sin-correo')->setRoles(['ROLE_ADMIN']);

        $audience = $this->audience([
            $this->user('admin@test.org', ['ROLE_ADMIN']),
            $this->user('admin@test.org', ['ROLE_ADMIN']),
            $sinCorreo,
        ]);

        $this->assertSame(['admin@test.org'], $audience->emailsWithRole('ROLE_ADMIN'));
    }

    /** Quien no alcanza el rol no recibe nada. */
    public function testNoDevuelveAQuienNoTieneElPermiso(): void
    {
        $audience = $this->audience([$this->user('socia@test.org', ['ROLE_PARTNER'])]);

        $this->assertSame([], $audience->emailsWithRole('ROLE_ADMIN'));
    }

    /**
     * @param list<User> $enabled las cuentas habilitadas que devuelve el repositorio
     */
    private function audience(array $enabled): StaffAudience
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findEnabled')->willReturn($enabled);

        return new StaffAudience($users, new RoleHierarchy(self::HIERARCHY));
    }

    /**
     * @param string[] $roles
     */
    private function user(string $email, array $roles): User
    {
        return (new User())
            ->setUsername($email)
            ->setEmail($email)
            ->setRoles($roles);
    }
}
