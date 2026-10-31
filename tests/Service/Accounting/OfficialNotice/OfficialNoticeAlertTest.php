<?php

namespace App\Tests\Service\Accounting\OfficialNotice;

use App\Entity\Notification;
use App\Entity\OfficialNotice;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Accounting\OfficialNotice\OfficialNoticeAlert;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * A quién llega el aviso de una notificación oficial y qué dice. Con la jerarquía
 * real: el admin alcanza el permiso de contabilidad sin tenerlo escrito, y el push
 * tiene que saltárselo, pero no a ninguno de los responsables.
 */
class OfficialNoticeAlertTest extends TestCase
{
    /** El trozo de security.yaml que importa aquí. */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_GESTION_CONTABILIDAD_EDIT'],
        'ROLE_GESTION_CONTABILIDAD_EDIT' => ['ROLE_GESTION_CONTABILIDAD'],
    ];

    /** Push a TODOS los responsables (no sólo al primero), bandeja también al admin, nada a quien sólo mira. */
    public function testLlegaATodosLosResponsables(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT']);
        $accountant = $this->user(3, ['ROLE_GESTION_CONTABILIDAD_EDIT']);
        $readOnly = $this->user(4, ['ROLE_GESTION_CONTABILIDAD']);
        $title = 'Notificación oficial (Seguridad Social)';

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')
            ->with([$admin, $treasurer, $accountant], Notification::KIND_ACCOUNTING_OFFICIAL_NOTICE, $title, $this->anything())
            ->willReturn(3);
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')
            ->with([$treasurer, $accountant], $title, $this->anything(), '/gestion/accounting/official-notices/', 'accounting_official_notice');

        $sent = $this->alert([$admin, $treasurer, $accountant, $readOnly], $inbox, $push)
            ->send(new OfficialNotice(str_repeat('c', 64), 'Seguridad Social', 'notificaciones.telematicas.giss@seg-social.es', new \DateTimeImmutable('2026-10-14')));

        $this->assertSame(3, $sent);
    }

    /** El texto es fijo: sólo cambia el organismo, que sale de la configuración y no del correo. */
    public function testElTextoEsFijo(): void
    {
        $this->assertSame(
            'Ha llegado un aviso de notificación oficial (DEHú). Escribe tú dehu.redsara.es (o la sede del organismo) y entra con certificado; no uses el enlace del correo. Si nadie la abre en 10 días naturales desde que se puso a disposición, cuenta como notificada y corren los plazos.',
            OfficialNoticeAlert::body('DEHú'),
        );
    }

    /**
     * El aviso con la jerarquía real y los canales simulados.
     *
     * @param list<User> $enabled Cuentas habilitadas.
     */
    private function alert(array $enabled, NotificationInbox $inbox, PushSender $push): OfficialNoticeAlert
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findEnabled')->willReturn($enabled);
        $link = $this->createStub(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/gestion/accounting/official-notices/');

        return new OfficialNoticeAlert(new StaffAudience($users, new RoleHierarchy(self::HIERARCHY)), $inbox, $push, $link);
    }

    /**
     * Una cuenta con id.
     *
     * @param list<string> $roles Roles escritos.
     */
    private function user(int $id, array $roles): User
    {
        $user = (new User())->setUsername('cuenta-' . $id)->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
