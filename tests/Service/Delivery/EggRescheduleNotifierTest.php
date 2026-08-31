<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Helper;
use App\Entity\Partner;
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
        return new EggRescheduleNotifier($mailer, $this->createMock(LoggerInterface::class));
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
}
