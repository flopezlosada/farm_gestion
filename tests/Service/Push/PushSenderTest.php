<?php

namespace App\Tests\Service\Push;

use App\Entity\NotificationLog;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\NotificationRecorder;
use App\Service\Push\PushDeliveryReport;
use App\Service\Push\PushSender;
use App\Service\Push\PushTransport;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * La política de envío push: a quién se le manda, qué se poda y qué no puede
 * reventar nunca.
 *
 * Lo que más se cuida es la poda. Un dispositivo muerto que no se borra se
 * arrastra en cada envío para siempre, y como el fallo es silencioso —el aviso
 * "sale", simplemente no llega a nadie— no lo detecta nadie hasta que el lote
 * tarda de más.
 */
class PushSenderTest extends TestCase
{
    /**
     * Un 404/410 del servicio de push significa que ese navegador ya no existe:
     * su fila se borra, y se hace UN solo flush aunque caigan varias.
     */
    public function testUnaSuscripcionMuertaSeBorra(): void
    {
        $viva = $this->subscription('https://fcm.googleapis.com/viva');
        $muerta = $this->subscription('https://fcm.googleapis.com/muerta');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($muerta);
        $entityManager->expects($this->once())->method('flush');

        $sender = $this->sender(
            [$viva, $muerta],
            [
                PushDeliveryReport::delivered('https://fcm.googleapis.com/viva'),
                PushDeliveryReport::gone('https://fcm.googleapis.com/muerta'),
            ],
            $entityManager
        );

        $this->assertSame(1, $sender->sendToMany([new User()], 'Falta gente', 'El jueves', '/panel', 'volunteer_call'));
    }

    /**
     * Un fallo pasajero (servicio caído, timeout) NO borra nada: la suscripción
     * sigue siendo buena y borrarla dejaría a esa persona sin avisos por una
     * caída de diez minutos.
     */
    public function testUnFalloPasajeroNoBorraLaSuscripcion(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $sender = $this->sender(
            [$this->subscription('https://fcm.googleapis.com/a')],
            [PushDeliveryReport::failed('https://fcm.googleapis.com/a', 'timeout')],
            $entityManager
        );

        $this->assertSame(0, $sender->sendToMany([new User()], 'Falta gente', null, '/panel', 'volunteer_call'));
    }

    /**
     * Sin claves VAPID el envío es un no-op silencioso, que es el estado normal
     * en local y en los tests. No debe reventar ni consultar nada.
     */
    public function testSinConfigurarNoHaceNada(): void
    {
        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(false);
        $transport->expects($this->never())->method('send');

        $subscriptions = $this->createMock(PushSubscriptionRepository::class);
        $subscriptions->expects($this->never())->method('findByUsers');

        $sender = new PushSender(
            $subscriptions,
            $this->createMock(EntityManagerInterface::class),
            $transport,
            new NullLogger(),
            $this->recorder()
        );

        $this->assertSame(0, $sender->sendToUser(new User(), 'Da igual', null, '/panel', 'volunteer_call'));
    }

    /**
     * Si el transporte lanza, el envío devuelve cero y no propaga: un push que
     * no sale no puede tumbar la operación que lo disparó ni abortar a medias la
     * tanda del planificador.
     */
    public function testUnFalloDelTransporteNoSePropaga(): void
    {
        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(true);
        $transport->method('send')->willThrowException(new \RuntimeException('la red no va'));

        $subscriptions = $this->createMock(PushSubscriptionRepository::class);
        $subscriptions->method('findByUsers')->willReturn([$this->subscription('https://fcm.googleapis.com/a')]);

        $sender = new PushSender(
            $subscriptions,
            $this->createMock(EntityManagerInterface::class),
            $transport,
            new NullLogger(),
            $this->recorder()
        );

        $this->assertSame(0, $sender->sendToMany([new User()], 'Falta gente', null, '/panel', 'volunteer_call'));
    }

    /**
     * Sin destinatarios no se consulta ni se manda nada. Parece obvio, y es el
     * caso que más veces va a darse: una oferta cuya audiencia ya está cubierta.
     */
    public function testSinDestinatariosNoConsultaNada(): void
    {
        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(true);
        $transport->expects($this->never())->method('send');

        $subscriptions = $this->createMock(PushSubscriptionRepository::class);
        $subscriptions->expects($this->never())->method('findByUsers');

        $sender = new PushSender(
            $subscriptions,
            $this->createMock(EntityManagerInterface::class),
            $transport,
            new NullLogger(),
            $this->recorder()
        );

        $this->assertSame(0, $sender->sendToMany([], 'Falta gente', null, '/panel', 'volunteer_call'));
    }

    /**
     * Cada persona alcanzada deja su línea en la bitácora, y quien no recibió
     * nada la deja también como fallo: es la diferencia entre "no le llegó" y
     * "no se intentó", que es justo lo que hay que poder distinguir cuando
     * alguien dice que no recibe avisos.
     */
    public function testApuntaEnLaBitacoraQuienRecibioYQuienNo(): void
    {
        $conSuerte = new User();
        $sinSuerte = new User();

        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->expects($this->once())
            ->method('sent')
            ->with(NotificationLog::CHANNEL_PUSH, 'volunteer_call', '1 navegador(es)', 'Falta gente');
        $recorder->expects($this->once())
            ->method('failed')
            ->with(NotificationLog::CHANNEL_PUSH, 'volunteer_call', '1 navegador(es)', $this->anything(), 'Falta gente');

        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(true);
        $transport->method('send')->willReturn([
            PushDeliveryReport::delivered('https://fcm.googleapis.com/ok'),
            PushDeliveryReport::failed('https://fcm.googleapis.com/ko', 'timeout'),
        ]);

        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->method('findByUsers')->willReturn([
            $this->subscription('https://fcm.googleapis.com/ok', $conSuerte),
            $this->subscription('https://fcm.googleapis.com/ko', $sinSuerte),
        ]);

        $sender = new PushSender(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $transport,
            new NullLogger(),
            $recorder,
        );

        $this->assertSame(1, $sender->sendToMany([$conSuerte, $sinSuerte], 'Falta gente', null, '/panel', 'volunteer_call'));
    }

    /**
     * Si revienta el lote entero, todos los que tenían dónde recibirlo quedan
     * apuntados como fallo. Sin esto, una caída del transporte se leería en el
     * registro igual que "no había nadie suscrito".
     */
    public function testUnFalloDelTransporteQuedaApuntado(): void
    {
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->expects($this->once())->method('failed');

        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(true);
        $transport->method('send')->willThrowException(new \RuntimeException('la red no va'));

        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->method('findByUsers')->willReturn([$this->subscription('https://fcm.googleapis.com/a')]);

        $sender = new PushSender(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            $transport,
            new NullLogger(),
            $recorder,
        );

        $this->assertSame(0, $sender->sendToMany([new User()], 'Falta gente', null, '/panel', 'volunteer_call'));
    }

    /** Un registrador que no comprueba nada, para los tests que miran otra cosa. */
    private function recorder(): NotificationRecorder
    {
        return $this->createMock(NotificationRecorder::class);
    }

    /**
     * @param string    $endpoint la URL del servicio de push
     * @param User|null $user     de quién es ese navegador
     */
    private function subscription(string $endpoint, ?User $user = null): PushSubscription
    {
        return (new PushSubscription())
            ->setUser($user ?? new User())
            ->setEndpoint($endpoint)
            ->setP256dh('clave-publica')
            ->setAuth('secreto');
    }

    /**
     * @param list<PushSubscription>  $subscriptions las suscripciones que devuelve el repositorio
     * @param list<PushDeliveryReport> $reports      los resultados que devuelve el transporte
     */
    private function sender(
        array $subscriptions,
        array $reports,
        EntityManagerInterface $entityManager,
    ): PushSender {
        $transport = $this->createMock(PushTransport::class);
        $transport->method('isConfigured')->willReturn(true);
        $transport->method('send')->willReturn($reports);

        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->method('findByUsers')->willReturn($subscriptions);

        return new PushSender($repository, $entityManager, $transport, new NullLogger(), $this->recorder());
    }
}
