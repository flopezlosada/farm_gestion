<?php

namespace App\Tests\Service\Accounting\Watch;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;
use App\Service\Accounting\Watch\AccountingWatchAlert;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * A quién llegan los avisos de la vigilancia y qué dicen. Con la jerarquía real: el
 * admin alcanza el permiso sin tenerlo escrito y recibe la bandeja, pero no el móvil;
 * quien sólo puede mirar la contabilidad no recibe nada.
 */
class AccountingWatchAlertTest extends TestCase
{
    /** El trozo de security.yaml que importa aquí. */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_GESTION_CONTABILIDAD_EDIT'],
        'ROLE_GESTION_CONTABILIDAD_EDIT' => ['ROLE_GESTION_CONTABILIDAD'],
    ];

    public function testLaTareaUrgenteLlegaAQuienEscribeEnContabilidad(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT']);
        $readOnly = $this->user(3, ['ROLE_GESTION_CONTABILIDAD']);
        $item = new AgendaItem(AgendaUrgency::Soon, 'La remesa de octubre no ha entrado', ['Se esperaba el 13/10.', 'Mira el banco.'], 'accounting_remittances');

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')
            ->with([$admin, $treasurer], Notification::KIND_ACCOUNTING_AGENDA, 'La remesa de octubre no ha entrado', 'Se esperaba el 13/10. Mira el banco.')
            ->willReturn(2);
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')
            ->with([$treasurer], 'La remesa de octubre no ha entrado', 'Se esperaba el 13/10. Mira el banco.', '/gestion/accounting/', AccountingWatchAlert::KIND_URGENT);

        $this->assertSame(2, $this->alert([$admin, $treasurer, $readOnly], $inbox, $push)->urgent($item));
    }

    public function testElResumenSemanalCuentaLasTareas(): void
    {
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT']);
        $items = [$this->normal('Revisar 3 facturas de la bandeja'), $this->normal('Anotar el libro de La Caixa')];

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')
            ->with([$treasurer], Notification::KIND_ACCOUNTING_AGENDA, 'Contabilidad: 2 tareas pendientes', 'Revisar 3 facturas de la bandeja. Anotar el libro de La Caixa.')
            ->willReturn(1);
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')
            ->with([$treasurer], 'Contabilidad: 2 tareas pendientes', $this->anything(), '/gestion/accounting/', AccountingWatchAlert::KIND_WEEKLY);

        $this->assertSame(1, $this->alert([$treasurer], $inbox, $push)->weekly($items));
    }

    /** Con muchas tareas, el resumen nombra las primeras y cuenta el resto. */
    public function testElResumenNoEsUnListado(): void
    {
        $items = array_map(fn (int $i): AgendaItem => $this->normal('Tarea ' . $i), range(1, AccountingWatchAlert::WEEKLY_LISTED + 3));

        $body = AccountingWatchAlert::weeklyBody($items);

        $this->assertStringStartsWith('Tarea 1. Tarea 2.', $body);
        $this->assertStringNotContainsString('Tarea ' . (AccountingWatchAlert::WEEKLY_LISTED + 1), $body);
        $this->assertStringEndsWith('Y 3 más en la portada.', $body);
        $this->assertSame('Tarea 1.', AccountingWatchAlert::weeklyBody([$this->normal('Tarea 1')]));
    }

    private function normal(string $title): AgendaItem
    {
        return new AgendaItem(AgendaUrgency::Normal, $title, ['Detalle.'], 'accounting_index');
    }

    /**
     * El aviso con la jerarquía real y los canales simulados.
     *
     * @param list<User> $enabled Cuentas habilitadas.
     */
    private function alert(array $enabled, NotificationInbox $inbox, PushSender $push): AccountingWatchAlert
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findEnabled')->willReturn($enabled);
        $link = $this->createStub(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/gestion/accounting/');

        return new AccountingWatchAlert(new StaffAudience($users, new RoleHierarchy(self::HIERARCHY)), $inbox, $push, $link);
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
