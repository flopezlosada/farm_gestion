<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Delivery\ClosureShiftNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit test del aviso por email a los socios cuyo cambio de reparto se anuló al
 * cerrarse una semana. Verifica que se envía uno por socio con email, que se
 * saltan (en silencio) los que no tienen email, y que el mensaje apunta a la
 * plantilla y datos correctos. El mailer va mockeado (no se envía nada real).
 */
class ClosureShiftNotifierTest extends TestCase
{
    public function testEnviaUnEmailPorSocioConEmailYDevuelveElConteo(): void
    {
        $ana = $this->partner('ana@test.org', 'Ana');
        $bea = $this->partner('bea@test.org', 'Bea');
        $week = (new Basket())->setDate(new \DateTime('2026-07-03'));

        $captured = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (TemplatedEmail $email) use (&$captured): void {
                $captured[] = $email;
            });

        $sent = $this->notifier($mailer)
            ->notifyCancelled([$ana, $bea], $week);

        $this->assertSame(2, $sent);
        $this->assertSame('email/closure_shift_cancelled.html.twig', $captured[0]->getHtmlTemplate());
        $this->assertSame('email/closure_shift_cancelled.txt.twig', $captured[0]->getTextTemplate());
        $this->assertSame($ana, $captured[0]->getContext()['partner']);
        $this->assertEquals($week->getDate(), $captured[0]->getContext()['closed_date']);
        $this->assertSame('ana@test.org', $captured[0]->getTo()[0]->getAddress());
    }

    /**
     * Los socios sin email (null o cadena vacía, como traen filas heredadas del
     * dump de prod) se saltan sin reventar y no cuentan en el total.
     */
    public function testSaltaSociosSinEmail(): void
    {
        $conEmail = $this->partner('valido@test.org', 'Con');
        $sinEmail = $this->partner(null, 'Nulo');
        $vacio = $this->partner('   ', 'Vacío');
        $week = (new Basket())->setDate(new \DateTime('2026-07-03'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $sent = $this->notifier($mailer)
            ->notifyCancelled([$conEmail, $sinEmail, $vacio], $week);

        $this->assertSame(1, $sent, 'Solo cuenta el socio con email válido.');
    }

    public function testListaVaciaNoEnviaNada(): void
    {
        $week = (new Basket())->setDate(new \DateTime('2026-07-03'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->assertSame(
            0,
            $this->notifier($mailer)->notifyCancelled([], $week),
        );
    }

    /**
     * Un fallo de transporte (SMTP caído) en un socio no debe abortar el bucle:
     * se loguea, no cuenta como enviado y se sigue con el resto. Así un cierre
     * guardado no revienta el redirect del admin por un email caído.
     */
    public function testUnFalloDeTransporteNoRompeElBucle(): void
    {
        $cae = $this->partner('cae@test.org', 'Cae');
        $ok = $this->partner('ok@test.org', 'Ok');
        $week = (new Basket())->setDate(new \DateTime('2026-07-03'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (TemplatedEmail $email): void {
                if ($email->getTo()[0]->getAddress() === 'cae@test.org') {
                    throw new TransportException('SMTP caído');
                }
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        // El logger propio de este caso (comprueba que se avisa del fallo de SMTP),
        // así que no vale el helper: se construye a mano con la firma completa.
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByPartners')->willReturn([]);

        $notifier = new ClosureShiftNotifier(
            $mailer,
            $logger,
            $repository,
            $this->createMock(NotificationInbox::class),
        );

        $sent = $notifier->notifyCancelled([$cae, $ok], $week);

        $this->assertSame(1, $sent, 'Solo cuenta el envío que tuvo éxito.');
    }

    private function partner(?string $email, string $name): Partner
    {
        $partner = (new Partner())->setname($name);
        if ($email !== null) {
            $partner->setemail($email);
        }

        return $partner;
    }

    /**
     * El notificador con la bandeja doblada.
     *
     * La bandeja se dobla y no se comprueba aquí: lo que estos casos fijan es el
     * correo. Que la copia se escriba tiene su propio caso
     * ({@see testDejaCopiaEnLaBandejaAunSinEmail()}).
     *
     * @param MailerInterface        $mailer el mailer doblado
     * @param NotificationInbox|null $inbox  la bandeja
     * @param list<User>             $users  las cuentas que devuelve el repositorio
     */
    private function notifier(MailerInterface $mailer, ?NotificationInbox $inbox = null, array $users = []): ClosureShiftNotifier
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByPartners')->willReturn($users);

        return new ClosureShiftNotifier(
            $mailer,
            $this->createMock(LoggerInterface::class),
            $repository,
            $inbox ?? $this->createMock(NotificationInbox::class),
        );
    }

    /**
     * LA COPIA SE ESCRIBE AUNQUE EL SOCIX NO TENGA CORREO, que es el agujero que
     * este aviso tenía: salía sólo por email y la mayoría de las fichas reales no
     * lo tienen informado, así que a esa gente se le anulaba un cambio que había
     * pedido y no se enteraba por ningún sitio.
     */
    public function testDejaCopiaEnLaBandejaAunSinEmail(): void
    {
        $sinEmail = (new Partner())->setname('Sin Correo');
        $week = (new Basket())->setDate(new \DateTime('2099-07-03'));

        $escritas = [];
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'users' => \count($users)];

                return \count($users);
            }
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $sent = $this->notifier($mailer, $inbox, [new User()])->notifyCancelled([$sinEmail], $week);

        $this->assertSame(0, $sent, 'Sin correo no sale ningún email.');
        $this->assertCount(1, $escritas, 'Pero la copia sí queda en su bandeja.');
        $this->assertSame(Notification::KIND_SHIFT_CANCELLED, $escritas[0]['kind']);
    }

    /**
     * Sin cuenta de acceso no hay bandeja donde mirar: no se escribe una fila que
     * nadie podría abrir.
     */
    public function testSinCuentaDeAccesoNoDejaCopia(): void
    {
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $this->notifier($this->createMock(MailerInterface::class), $inbox, [])
            ->notifyCancelled([(new Partner())->setname('Nadie')], (new Basket())->setDate(new \DateTime('2099-07-03')));
    }
}
