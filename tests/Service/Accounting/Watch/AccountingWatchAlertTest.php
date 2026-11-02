<?php

namespace App\Tests\Service\Accounting\Watch;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\Agenda\AgendaUrgency;
use App\Service\Accounting\Watch\AccountingWatchAlert;
use App\Service\AppSettings;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * A quién llegan los avisos de la vigilancia y qué dicen. Con la jerarquía real: el
 * admin alcanza el permiso sin tenerlo escrito y recibe la bandeja, pero no el móvil
 * ni el correo; con el permiso escrito, sí; quien sólo puede mirar la contabilidad no
 * recibe nada.
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

        $this->assertSame(2, $this->alert([$admin, $treasurer, $readOnly], $inbox, $push, $this->createStub(MailerInterface::class))->urgent($item));
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

        $this->assertSame(1, $this->alert([$treasurer], $inbox, $push, $this->createStub(MailerInterface::class))->weekly($items));
    }

    public function testElCorreoVaALosResponsablesConUnaLineaPorDetalle(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN'], 'admin@example.test');
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT'], 'tesorera@example.test');
        $item = new AgendaItem(AgendaUrgency::Soon, 'Mandar el 3T2026 a la gestoría', ['12 facturas.', '1 gasto sin factura.'], 'accounting_index');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->with($this->callback(static function (TemplatedEmail $email): bool {
            return ['tesorera@example.test'] === array_map(static fn ($a) => $a->getAddress(), $email->getTo())
                && 'CSA Vega · Mandar el 3T2026 a la gestoría' === $email->getSubject()
                && ['12 facturas.', '1 gasto sin factura.'] === $email->getContext()['lines']
                && 'https://csa.test/gestion/accounting/' === $email->getContext()['url'];
        }));

        $this->alert([$admin, $treasurer], $this->createStub(NotificationInbox::class), $this->createStub(PushSender::class), $mailer)->urgent($item);
    }

    /** Un admin que lleva la contabilidad: con el permiso escrito, le llega como a cualquiera. */
    public function testUnAdminConElPermisoEscritoRecibeMovilYCorreo(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN', 'ROLE_GESTION_CONTABILIDAD_EDIT'], 'admin@example.test');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')
            ->with($this->callback(static fn (TemplatedEmail $email): bool => 'admin@example.test' === $email->getTo()[0]->getAddress()));
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')->with([$admin]);

        $alert = $this->alert([$admin], $this->createStub(NotificationInbox::class), $push, $mailer);

        $this->assertTrue($alert->hasResponsibles());
        $alert->urgent($this->normal('Tarea'));
    }

    /** Sólo admins sin el permiso escrito: nadie lleva la contabilidad. */
    public function testSinPermisoEscritoNoHayResponsables(): void
    {
        $alert = $this->alert(
            [$this->user(1, ['ROLE_ADMIN']), $this->user(3, ['ROLE_GESTION_CONTABILIDAD'])],
            $this->createStub(NotificationInbox::class),
            $this->createStub(PushSender::class),
            $this->createStub(MailerInterface::class),
        );

        $this->assertFalse($alert->hasResponsibles());
    }

    /** Con el correo apagado en Ajustes, el móvil y la bandeja siguen avisando. */
    public function testConElCorreoApagadoSiguenElMovilYLaBandeja(): void
    {
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT'], 'tesorera@example.test');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')->willReturn(1);
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany');

        $this->assertSame(1, $this->alert([$treasurer], $inbox, $push, $mailer, false)->urgent($this->normal('Tarea')));
    }

    /**
     * Si el correo falla no se deja nada en la bandeja ni en el móvil: el reintento lo
     * manda entero y no duplica.
     */
    public function testSiElCorreoFallaNoSeAvisaPorOtroLado(): void
    {
        $treasurer = $this->user(2, ['ROLE_GESTION_CONTABILIDAD_EDIT'], 'tesorera@example.test');

        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('SMTP caído'));
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');
        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->expectException(\RuntimeException::class);
        $this->alert([$treasurer], $inbox, $push, $mailer)->urgent($this->normal('Tarea'));
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
     * @param list<User> $enabled      Cuentas habilitadas.
     * @param bool       $emailEnabled Si el correo de la vigilancia está encendido.
     */
    private function alert(array $enabled, NotificationInbox $inbox, PushSender $push, MailerInterface $mailer, bool $emailEnabled = true): AccountingWatchAlert
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findEnabled')->willReturn($enabled);
        $link = $this->createStub(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/gestion/accounting/');
        $settings = $this->createStub(AppSettings::class);
        $settings->method('getBool')->willReturnMap([[AppSettings::EMAIL_ENABLED, true], [AppSettings::EMAIL_ACCOUNTING_WATCH, $emailEnabled]]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://csa.test/gestion/accounting/');

        return new AccountingWatchAlert(new StaffAudience($users, new RoleHierarchy(self::HIERARCHY)), $inbox, $push, $link, $mailer, $settings, $urls);
    }

    /**
     * Una cuenta con id.
     *
     * @param list<string> $roles Roles escritos.
     * @param string|null  $email Correo, si tiene.
     */
    private function user(int $id, array $roles, ?string $email = null): User
    {
        $user = (new User())->setUsername('cuenta-' . $id)->setRoles($roles);
        if (null !== $email) {
            $user->setEmail($email);
        }
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
