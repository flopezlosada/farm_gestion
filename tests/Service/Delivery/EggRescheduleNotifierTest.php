<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Helper;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Delivery\EggRescheduleNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit test del aviso de retirada o traslado de huevos. Comprueba que sale un
 * email por afectado con email, que socios y voluntarios se resuelven por vías
 * distintas, que el asunto distingue traslado de retirada, y que un fallo de
 * SMTP no tumba el resto del lote. El mailer va mockeado (no se envía nada).
 */
class EggRescheduleNotifierTest extends TestCase
{
    public function testAvisaASociosYVoluntariosDelTraslado(): void
    {
        $from = (new Basket())->setDate(new \DateTime('2026-09-04'));
        $to = (new Basket())->setDate(new \DateTime('2026-09-11'));

        $captured = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (TemplatedEmail $email) use (&$captured): void {
                $captured[] = $email;
            });

        $sent = $this->notifier($mailer)->notify(
            [
                $this->partnerRow('ana@test.org', 'Ana'),
                $this->helperRow('german@test.org', 'Germán'),
            ],
            $from,
            $to,
        );

        $this->assertSame(2, $sent);
        $this->assertSame('email/egg_reschedule.html.twig', $captured[0]->getHtmlTemplate());
        $this->assertSame('email/egg_reschedule.txt.twig', $captured[0]->getTextTemplate());
        $this->assertStringContainsString('cambian de semana', $captured[0]->getSubject());
        $this->assertSame('ana@test.org', $captured[0]->getTo()[0]->getAddress());
        $this->assertFalse($captured[0]->getContext()['is_helper']);
        $this->assertEquals($to->getDate(), $captured[0]->getContext()['to_date']);

        $this->assertSame('german@test.org', $captured[1]->getTo()[0]->getAddress());
        $this->assertTrue($captured[1]->getContext()['is_helper'], 'El voluntario se marca como tal: su copy no habla de cuota.');
    }

    public function testLaRetiradaLlevaOtroAsuntoYSinDestino(): void
    {
        $from = (new Basket())->setDate(new \DateTime('2026-09-04'));

        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (TemplatedEmail $email) use (&$captured): void {
            $captured = $email;
        });

        $this->notifier($mailer)->notify([$this->partnerRow('ana@test.org', 'Ana')], $from, null);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('no hay huevos', $captured->getSubject());
        $this->assertNull($captured->getContext()['to_date']);
    }

    public function testSaltaAQuienNoTieneEmail(): void
    {
        $from = (new Basket())->setDate(new \DateTime('2026-09-04'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $sent = $this->notifier($mailer)->notify(
            [$this->partnerRow(null, 'Sin email'), $this->partnerRow('  ', 'Email en blanco')],
            $from,
            null,
        );

        $this->assertSame(0, $sent);
    }

    /**
     * Un SMTP que falla con uno no puede dejar sin avisar a los demás: el lote
     * ya se aplicó y el resto tiene que enterarse.
     */
    public function testUnFalloDeEnvioNoDetieneAlResto(): void
    {
        $from = (new Basket())->setDate(new \DateTime('2026-09-04'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(static function (TemplatedEmail $email): void {
                if ($email->getTo()[0]->getAddress() === 'rota@test.org') {
                    throw new TransportException('SMTP caído');
                }
            });

        $sent = $this->notifier($mailer)->notify(
            [$this->partnerRow('rota@test.org', 'Rota'), $this->partnerRow('ok@test.org', 'Ok')],
            $from,
            null,
        );

        $this->assertSame(1, $sent);
    }

    private function notifier(MailerInterface $mailer): EggRescheduleNotifier
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByPartners')->willReturn($this->cuentas);

        return new EggRescheduleNotifier(
            $mailer,
            $this->createMock(LoggerInterface::class),
            $repository,
            $this->inbox ?? $this->createMock(NotificationInbox::class),
        );
    }

    /**
     * @return array{kind: 'partner', name: string, partner: Partner, helper: null}
     */
    private function partnerRow(?string $email, string $name): array
    {
        $partner = new Partner();
        $partner->setName($name);
        if ($email !== null) {
            $partner->setemail($email);
        }

        return ['kind' => 'partner', 'name' => $name, 'partner' => $partner, 'helper' => null];
    }

    /**
     * @return array{kind: 'helper', name: string, partner: null, helper: Helper}
     */
    private function helperRow(string $email, string $name): array
    {
        $helper = (new Helper())->setName($name)->setEmail($email);

        return ['kind' => 'helper', 'name' => $name, 'partner' => null, 'helper' => $helper];
    }

    /**
     * Cuentas de acceso que devuelve el repositorio doblado. Vacío por defecto: los
     * casos del correo no hablan de la bandeja.
     *
     * @var list<User>
     */
    private array $cuentas = [];

    /** La bandeja doblada, cuando un caso quiere inspeccionarla. */
    private ?NotificationInbox $inbox = null;

    /**
     * LA COPIA SE ESCRIBE AUNQUE EL SOCIX NO TENGA CORREO. Es el agujero que este
     * aviso tenía: salía sólo por email y la mayoría de las fichas reales no lo
     * tienen informado, así que a esa gente se le retiraban los huevos y se
     * plantaba en el punto de recogida esperando su docena.
     */
    public function testDejaCopiaEnLaBandejaAunSinEmail(): void
    {
        $escritas = [];
        $this->inbox = $this->createMock(NotificationInbox::class);
        $this->inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title, ?string $body) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'body' => $body];

                return \count($users);
            }
        );
        $this->cuentas = [new User()];

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $sent = $this->notifier($mailer)->notify(
            [$this->partnerRow(null, 'Sin Correo')],
            (new Basket())->setDate(new \DateTime('2099-07-03')),
            null,
        );

        $this->assertSame(0, $sent, 'Sin correo no sale ningún email.');
        $this->assertCount(1, $escritas, 'Pero la copia sí queda en su bandeja.');
        $this->assertSame(Notification::KIND_EGGS_RESCHEDULED, $escritas[0]['kind']);
        $this->assertSame('Esta semana no hay huevos', $escritas[0]['title']);
    }

    /**
     * A lxs voluntarixs del albergue no se les deja copia: no son socixs, no tienen
     * cuenta de acceso ni bandeja. Su vía sigue siendo el correo.
     */
    public function testALosVoluntariosDelAlbergueNoSeLesDejaCopia(): void
    {
        $this->inbox = $this->createMock(NotificationInbox::class);
        $this->inbox->expects($this->never())->method('deliver');
        $this->cuentas = [new User()];

        $this->notifier($this->createMock(MailerInterface::class))->notify(
            [$this->helperRow('voluntarix@ejemplo.org', 'Alguien de fuera')],
            (new Basket())->setDate(new \DateTime('2099-07-03')),
            null,
        );
    }
}
