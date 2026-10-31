<?php

namespace App\Tests\Service\Accounting\OfficialNotice;

use App\Entity\OfficialNotice;
use App\Repository\OfficialNoticeRepository;
use App\Service\Accounting\OfficialNotice\NoticeOutcome;
use App\Service\Accounting\OfficialNotice\OfficialNoticeAlert;
use App\Service\Accounting\OfficialNotice\OfficialNoticeIntake;
use App\Service\Accounting\OfficialNotice\OfficialSenders;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Los correos del buzón que dicen venir de un organismo oficial, en crudo y con las
 * cabeceras como las deja un servidor de verdad: la Authentication-Results de nuestro
 * servidor arriba del todo, y debajo lo que traiga el correo.
 *
 * Lo que se vigila es que sólo avise lo firmado por el organismo, y que quien
 * suplanta al remitente (el caso que la Seguridad Social advierte) no consiga un
 * aviso de la app que dé credibilidad a su enlace.
 */
class OfficialNoticeIntakeTest extends TestCase
{
    /** Lo que pone nuestro servidor cuando la firma de la DEHú es buena. */
    private const DEHU_SIGNED = 'mx.buzon.test; dkim=pass (2048-bit key) header.d=correo.gob.es header.s=sel1 header.b=Zm9v; spf=pass smtp.mailfrom=correo.gob.es; dmarc=pass header.from=correo.gob.es';

    private const NOW = '2026-10-14 10:00:00';

    /** Firmado por la DEHú: se guarda (antes de avisar) y se avisa una vez, con la fecha del correo. */
    public function testFirmaValidaGuardaYAvisa(): void
    {
        $saved = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(static function (OfficialNotice $n) use (&$saved): void {
            $saved = $n;
        });
        $em->expects($this->once())->method('flush');
        $alert = $this->createMock(OfficialNoticeAlert::class);
        $alert->expects($this->once())->method('send')->with($this->callback(static function (OfficialNotice $n) use (&$saved): bool {
            return $n === $saved;
        }));

        $outcome = $this->intake(em: $em, alert: $alert)->take($this->mail([self::DEHU_SIGNED]), new \DateTimeImmutable(self::NOW));

        $this->assertSame(NoticeOutcome::Alerted, $outcome);
        $this->assertSame('DEHú', $saved->getAgency());
        $this->assertSame('noreply.dehu@correo.gob.es', $saved->getSender());
        $this->assertSame('2026-10-14 09:12', $saved->getReceivedAt()->format('Y-m-d H:i'));
        $this->assertSame(OfficialNotice::keyFor('aviso-0001@correo.gob.es', ''), $saved->getMessageKey());
        $this->assertFalse($saved->isReviewed());
    }

    /** El remitente en mayúsculas es el mismo remitente. */
    public function testRemitenteConMayusculas(): void
    {
        $raw = $this->mail([self::DEHU_SIGNED], from: 'Direccion Electronica Habilitada <NoReply.DEHU@Correo.Gob.ES>');

        $this->assertSame(NoticeOutcome::Alerted, $this->intake()->take($raw, new \DateTimeImmutable(self::NOW)));
    }

    /** Sin Authentication-Results (o sin firma DKIM en ella), no hay prueba: sospechoso, sin aviso ni fila. */
    public function testFirmaAusenteEsSospechoso(): void
    {
        foreach ([[], ['mx.buzon.test; spf=pass smtp.mailfrom=correo.gob.es'], ['mx.buzon.test; dkim=none']] as $headers) {
            $this->assertSuspicious($this->mail($headers));
        }
    }

    /** Firma válida, pero de otro dominio (uno que se le parece incluido): sospechoso. */
    public function testFirmaDeOtroDominioEsSospechoso(): void
    {
        $this->assertSuspicious($this->mail(['mx.buzon.test; dkim=pass header.d=correo-gob.es.example.test']));
        $this->assertSuspicious($this->mail(['mx.buzon.test; dkim=pass header.d=gob.es']), 'gob.es es el padre, no un subdominio de correo.gob.es.');
        $this->assertSuspicious($this->mail(['mx.buzon.test; dkim=pass header.d=seg-social.es']), 'La firma de otro organismo no vale.');
    }

    /**
     * EL ATAQUE: quien suplanta pone su propia Authentication-Results con un «pass»;
     * nuestro servidor añade la suya encima y dice que la firma falla. Manda la de arriba.
     */
    public function testAuthenticationResultsFalsoMasAbajoNoCuenta(): void
    {
        $this->assertSuspicious($this->mail([
            'mx.buzon.test; dkim=fail (signature verification failed) header.d=correo.gob.es; spf=fail smtp.mailfrom=correo.gob.es',
            self::DEHU_SIGNED,
        ]));
        $this->assertSuspicious($this->mail(['mx.buzon.test; spf=none', self::DEHU_SIGNED]), 'Arriba sin firma: la de abajo no la suple.');
    }

    /** Al revés, una de abajo que diga que falla no tumba la buena de arriba. */
    public function testLaDeArribaBuenaMandaSobreUnaDeAbajo(): void
    {
        $raw = $this->mail([self::DEHU_SIGNED, 'relay.intermedio.test; dkim=fail header.d=correo.gob.es']);

        $this->assertSame(NoticeOutcome::Alerted, $this->intake()->take($raw, new \DateTimeImmutable(self::NOW)));
    }

    /** El mismo correo (mismo Message-ID) no avisa dos veces. */
    public function testMessageIdRepetidoNoAvisa(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $alert = $this->createMock(OfficialNoticeAlert::class);
        $alert->expects($this->never())->method('send');

        $outcome = $this->intake(known: [OfficialNotice::keyFor('<aviso-0001@correo.gob.es>', '')], em: $em, alert: $alert)
            ->take($this->mail([self::DEHU_SIGNED]), new \DateTimeImmutable(self::NOW));

        $this->assertSame(NoticeOutcome::Repeated, $outcome);
        $this->assertTrue($outcome->isOfficial(), 'Repetido o no, no es una factura.');
    }

    /** Sin Message-ID, el correo entero hace de huella: el mismo correo se reconoce, otro no. */
    public function testSinMessageIdLaHuellaEsElCorreo(): void
    {
        $raw = $this->mail([self::DEHU_SIGNED], messageId: null);

        $this->assertSame(NoticeOutcome::Repeated, $this->intake(known: [OfficialNotice::keyFor(null, $raw)])->take($raw, new \DateTimeImmutable(self::NOW)));
        $this->assertSame(NoticeOutcome::Alerted, $this->intake(known: [OfficialNotice::keyFor(null, $raw . ' ')])->take($raw, new \DateTimeImmutable(self::NOW)));
    }

    /** Una fecha de envío en el futuro (reloj mal puesto en origen) se queda en ahora. */
    public function testFechaFuturaSeQuedaEnAhora(): void
    {
        $saved = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (OfficialNotice $n) use (&$saved): void {
            $saved = $n;
        });

        $this->intake(em: $em)->take($this->mail([self::DEHU_SIGNED], date: 'Fri, 20 Nov 2026 09:00:00 +0100'), new \DateTimeImmutable(self::NOW));

        $this->assertSame(self::NOW, $saved->getReceivedAt()->format('Y-m-d H:i:s'));
    }

    /** Una factura normal no es cosa de este servicio: ni fila, ni aviso, ni log. */
    public function testCorreoDeFacturaSigueSuCamino(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $alert = $this->createMock(OfficialNoticeAlert::class);
        $alert->expects($this->never())->method('send');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $raw = $this->mail(['mx.buzon.test; dkim=pass header.d=proveedor.test'], from: 'Facturacion <facturas@proveedor.test>', subject: 'Factura F-2026-0412');
        $outcome = $this->intake(em: $em, alert: $alert, logger: $logger)->take($raw, new \DateTimeImmutable(self::NOW));

        $this->assertSame(NoticeOutcome::NotOfficial, $outcome);
        $this->assertFalse($outcome->isOfficial());
    }

    /**
     * Comprueba que el correo queda como sospechoso: ni fila, ni aviso, y un warning en el log.
     *
     * @param string $raw     Correo en crudo.
     * @param string $message Por qué.
     */
    private function assertSuspicious(string $raw, string $message = ''): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $alert = $this->createMock(OfficialNoticeAlert::class);
        $alert->expects($this->never())->method('send');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $outcome = $this->intake(em: $em, alert: $alert, logger: $logger)->take($raw, new \DateTimeImmutable(self::NOW));

        $this->assertSame(NoticeOutcome::Suspicious, $outcome, $message);
        $this->assertTrue($outcome->isOfficial(), 'Un sospechoso tampoco va a la bandeja de facturas.');
    }

    /**
     * El servicio con la lista de organismos de la configuración.
     *
     * @param list<string> $known Huellas de los correos de los que ya se avisó.
     */
    private function intake(
        array $known = [],
        ?EntityManagerInterface $em = null,
        ?OfficialNoticeAlert $alert = null,
        ?LoggerInterface $logger = null,
    ): OfficialNoticeIntake {
        $notices = $this->createStub(OfficialNoticeRepository::class);
        $notices->method('hasKey')->willReturnCallback(static fn (string $key): bool => \in_array($key, $known, true));

        return new OfficialNoticeIntake(
            new OfficialSenders([
                'noreply.dehu@correo.gob.es' => ['agency' => 'DEHú', 'dkim' => ['correo.gob.es']],
                'agenciatributaria@correo.aeat.es' => ['agency' => 'Agencia Tributaria', 'dkim' => ['aeat.es', 'correo.aeat.es']],
                'notificaciones.telematicas.giss@seg-social.es' => ['agency' => 'Seguridad Social', 'dkim' => ['seg-social.es']],
            ]),
            $notices,
            $alert ?? $this->createStub(OfficialNoticeAlert::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    /**
     * Un aviso de la DEHú como llega al buzón: las Authentication-Results de arriba
     * abajo (la primera, la de nuestro servidor), un Received entre medias y el cuerpo
     * con el enlace que nunca hay que seguir.
     *
     * @param list<string> $authResults Valores de Authentication-Results, de arriba abajo.
     */
    private function mail(
        array $authResults,
        string $from = 'Direccion Electronica Habilitada <noreply.dehu@correo.gob.es>',
        ?string $messageId = '<aviso-0001@correo.gob.es>',
        string $date = 'Wed, 14 Oct 2026 09:12:00 +0200',
        string $subject = 'Aviso de puesta a disposicion de notificacion',
    ): string {
        $headers = [];
        foreach ($authResults as $i => $value) {
            $headers[] = 'Authentication-Results: ' . $value;
            if ($i === 0) {
                $headers[] = "Received: from relay.intermedio.test (relay.intermedio.test [192.0.2.10])\r\n\tby mx.buzon.test with ESMTPS; " . $date;
            }
        }
        $headers[] = 'From: ' . $from;
        $headers[] = 'To: facturas@asociacion.test';
        $headers[] = 'Subject: ' . $subject;
        if ($messageId !== null) {
            $headers[] = 'Message-ID: ' . $messageId;
        }
        $headers[] = 'Date: ' . $date;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=utf-8';

        return implode("\r\n", $headers) . "\r\n\r\nTiene una notificacion disponible. Acceda en https://enlace-del-correo.example.test/acceso\r\n";
    }
}
