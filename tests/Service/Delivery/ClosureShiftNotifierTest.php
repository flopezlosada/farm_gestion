<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
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

        $sent = (new ClosureShiftNotifier($mailer, $this->createMock(LoggerInterface::class)))
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

        $sent = (new ClosureShiftNotifier($mailer, $this->createMock(LoggerInterface::class)))
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
            (new ClosureShiftNotifier($mailer, $this->createMock(LoggerInterface::class)))->notifyCancelled([], $week),
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

        $sent = (new ClosureShiftNotifier($mailer, $logger))->notifyCancelled([$cae, $ok], $week);

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
}
