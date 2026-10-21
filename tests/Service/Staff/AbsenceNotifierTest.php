<?php

namespace App\Tests\Service\Staff;

use App\Entity\Absence;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use App\Service\Staff\AbsenceNotifier;
use App\Service\Staff\WorkerNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * A quién de gestión le llega una ausencia que pide o registra un trabajador.
 *
 * La bandeja y el móvil tienen audiencias distintas a propósito: la bandeja a
 * todo el que puede decidir, admins incluidos; el push sólo a quien lleva el
 * personal. Con la jerarquía real, ROLE_ADMIN alcanza el permiso de personal
 * sin tenerlo escrito, que es justo lo que el push tiene que saltarse.
 */
class AbsenceNotifierTest extends TestCase
{
    /** El trozo de security.yaml que importa aquí. */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_GESTION_LABORAL_EDIT', 'ROLE_WORKER'],
        'ROLE_GESTION_LABORAL_EDIT' => ['ROLE_GESTION_LABORAL'],
    ];

    /** Bandeja a quien decide, admin incluido; push sólo al responsable. */
    public function testElPushVaAlResponsableYNoAlAdmin(): void
    {
        $admin = $this->user(1, 'admin', ['ROLE_ADMIN']);
        $responsible = $this->user(2, 'responsable', ['ROLE_GESTION_LABORAL_EDIT']);
        $readOnly = $this->user(3, 'solo-lectura', ['ROLE_GESTION_LABORAL']);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')
            ->with([$admin, $responsible], Notification::KIND_STAFF_ABSENCE_REQUESTED, 'Lucía pide vacaciones', $this->stringContains('Falta aprobarlas'))
            ->willReturn(2);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')
            ->with([$responsible], 'Lucía pide vacaciones', $this->anything(), '/gestion/staff', 'staff_absence_requested');

        $notifier = $this->notifier([$admin, $responsible, $readOnly], null, $inbox, $push);

        $this->assertSame(2, $notifier->requested($this->absence(Absence::STATUS_REQUESTED)));
    }

    /**
     * Sin responsable de personal no sale ningún push: el admin no es el
     * suplente. Queda la bandeja.
     */
    public function testSinResponsableNoHayPushPeroSiBandeja(): void
    {
        $admin = $this->user(1, 'admin', ['ROLE_ADMIN']);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')->with([$admin])->willReturn(1);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->notifier([$admin], null, $inbox, $push)->requested($this->absence(Absence::STATUS_APPROVED));
    }

    /**
     * El trabajador que además lleva el personal no se avisa a sí mismo de lo
     * que acaba de hacer, ni en la bandeja ni en el móvil.
     */
    public function testElPropioTrabajadorNoSeAvisaASiMismo(): void
    {
        $self = $this->user(5, 'trabajadora-responsable', ['ROLE_GESTION_LABORAL_EDIT']);
        $other = $this->user(6, 'otro-responsable', ['ROLE_GESTION_LABORAL_EDIT']);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')->with([$other])->willReturn(1);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')->with([$other]);

        $this->notifier([$self, $other], $self, $inbox, $push)->requested($this->absence(Absence::STATUS_REQUESTED));
    }

    /** La decisión le llega al trabajador, por bandeja y al móvil. */
    public function testLaDecisionLlegaAlTrabajador(): void
    {
        $account = $this->user(7, 'lucia', ['ROLE_WORKER']);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')
            ->with([$account], Notification::KIND_WORK_ABSENCE_DECIDED, 'Solicitud rechazada: vacaciones', $this->stringContains('Del 02/11/2026 al 06/11/2026.'));

        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToUser')
            ->with($account, 'Solicitud rechazada: vacaciones', $this->anything(), '/gestion/staff', 'work_absence_decided');

        $this->assertSame(1, $this->notifier([], $account, $inbox, $push)->decided($this->absence(Absence::STATUS_REJECTED)));
    }

    /** Un trabajador sin cuenta no tiene dónde enterarse: no sale nada. */
    public function testSinCuentaNoSeAvisaALaDecision(): void
    {
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToUser');

        $this->assertSame(0, $this->notifier([], null, $inbox, $push)->decided($this->absence(Absence::STATUS_APPROVED)));
    }

    /**
     * El notificador con la jerarquía real y la red de envío simulada.
     *
     * @param list<User> $enabled cuentas habilitadas
     * @param User|null  $own     la cuenta del trabajador de la ausencia
     */
    private function notifier(array $enabled, ?User $own, NotificationInbox $inbox, PushSender $push): AbsenceNotifier
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findEnabled')->willReturn($enabled);
        $users->method('findOneBy')->willReturn($own);

        $link = $this->createMock(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/gestion/staff');

        return new AbsenceNotifier(
            new StaffAudience($users, new RoleHierarchy(self::HIERARCHY)),
            new WorkerNotifier($users, $inbox, $push, $link),
            $inbox,
            $push,
            $link,
        );
    }

    /**
     * Unas vacaciones de Lucía, del 2 al 6 de noviembre.
     *
     * @param string $status Absence::STATUS_*
     */
    private function absence(string $status): Absence
    {
        return (new Absence())
            ->setWorker((new Worker())->setName('Lucía'))
            ->setType(Absence::TYPE_VACATION)
            ->setStartDate(new \DateTimeImmutable('2026-11-02'))
            ->setEndDate(new \DateTimeImmutable('2026-11-06'))
            ->setStatus($status);
    }

    /**
     * Una cuenta con id, que es lo que usa el filtro de «no avisarse a sí mismo».
     *
     * @param string[] $roles
     */
    private function user(int $id, string $username, array $roles): User
    {
        $user = (new User())->setUsername($username)->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
