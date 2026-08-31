<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Repository\UserRepository;
use App\Repository\VolunteerOfferRepository;
use App\Service\AppSettings;
use App\Service\Push\PushSender;
use App\Security\PartnerAccessPolicy;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallEscalator;
use App\Service\Volunteering\VolunteerCallNotifier;
use App\Service\Volunteering\VolunteerOfferFormatter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * La orquestación del aviso: qué pasa entre "toca avisar" y "avisado".
 *
 * Lo que se fija aquí son las formas de NO mandar: con el módulo apagado, sin
 * nadie a quien avisar por ninguna vía, y cuando otro proceso se ha adelantado.
 * Todas acaban en un aviso no enviado, que es siempre el error barato; el caro es
 * el aviso repetido.
 *
 * Y desde la bandeja de avisos, la frontera de "nadie a quien avisar" se ha
 * movido: la copia in-app llega a quien ha apagado el push y el correo, así que
 * ese caso ya NO es un no-aviso y tiene que registrarse igual. Está cubierto en
 * {@see testConTodosLosCanalesApagadosSigueAvisandoEnLaBandejaYRegistra()}.
 */
class VolunteerCallNotifierTest extends TestCase
{
    /**
     * Con el módulo apagado no se toca nada, ni siquiera se consultan ofertas.
     * Es lo que hace que el toggle sea de verdad un interruptor y no un adorno.
     */
    public function testConElModuloApagadoNoHaceNada(): void
    {
        $offers = $this->createMock(VolunteerOfferRepository::class);
        $offers->expects($this->never())->method('findUpcoming');

        $notifier = $this->notifier(enabled: false, offers: $offers);

        $this->assertSame(0, $notifier->dispatchDue(new \DateTimeImmutable('2099-03-01 10:00')));
    }

    /**
     * Sin nadie a quien avisar no se registra la llamada: así el alcance sigue
     * disponible si más adelante entra gente que sí encaje. Registrarla gastaría
     * el UNIQUE (offer, scope) por un aviso que no salió.
     */
    public function testSinDestinatariosNoRegistraLaLlamada(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $notifier = $this->notifier(
            audience: $this->audienceReturning([]),
            entityManager: $entityManager,
            push: $push
        );

        $this->assertNull($notifier->dispatch(
            $this->offer(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        ));
    }

    /**
     * Quien no tiene cuenta de acceso SÍ recibe el aviso: por correo.
     *
     * Antes no, y era correcto mientras esto sólo mandaba push —un push
     * necesita una sesión detrás—. Desde que el mismo aviso sale también por
     * correo, dejar fuera a quien no ha entrado nunca a la aplicación sería
     * excluir justo a la parte del colectivo con menos trato con el software,
     * que es a la que más cuesta llegar.
     */
    public function testQuienNoTieneCuentaRecibeElAvisoPorCorreo(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');

        // Nadie con cuenta: no hay a quien mandarle push.
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([]);

        // Se comprueba a QUIÉN llega el push, no si se llama al enviador: el
        // notificador le pasa la lista vacía y ahí no se manda nada. Afirmar que
        // no se le llama ataría el test a un detalle de implementación.
        $destinatariosPush = null;
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users) use (&$destinatariosPush): int {
                $destinatariosPush = $users;

                return 0;
            }
        );

        // Y tampoco copia en la bandeja: sin cuenta no hay bandeja donde mirar, y
        // escribirla sería una fila que nadie puede abrir.
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $notifier = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1)]),
            users: $users,
            entityManager: $entityManager,
            push: $push,
            inbox: $inbox
        );

        $call = $notifier->dispatch(
            $this->offer(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        );

        $this->assertNotNull($call, 'Se registra la llamada: al socix se le avisa por correo.');
        $this->assertSame(1, $call->getRecipients());
        $this->assertSame([], $destinatariosPush ?? [], 'Sin cuenta no hay push, sólo correo.');
    }

    /**
     * CAMBIO DE COMPORTAMIENTO CON LA BANDEJA, y el caso que más importa de este
     * fichero. Antes, si toda la audiencia tenía el push y el correo apagados,
     * esto devolvía null y no registraba nada: no había aviso posible. Ahora la
     * copia de la bandeja SÍ sale —es el suelo— y por eso hay que registrar la
     * llamada: sin la fila, el tick de la hora siguiente volvería a dejar la misma
     * fila en la bandeja de todo el mundo, y el de la siguiente otra vez.
     */
    public function testConTodosLosCanalesApagadosSigueAvisandoEnLaBandejaYRegistra(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $escritas = [];
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'users' => \count($users)];

                return \count($users);
            }
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([new User(), new User()]);

        $call = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1), $this->partner(2)]),
            users: $users,
            entityManager: $entityManager,
            push: $push,
            inbox: $inbox,
            // Nadie quiere el aviso por ningún canal configurable.
            preferences: $this->preferences([]),
        )->dispatch($this->offer(), VolunteerCall::SCOPE_MATCHING, null, new \DateTimeImmutable('2099-03-01 10:00'));

        $this->assertNotNull($call, 'La llamada se registra: el aviso salió, aunque sólo a la bandeja.');
        $this->assertCount(1, $escritas);
        $this->assertSame(Notification::KIND_VOLUNTEERING_CALL, $escritas[0]['kind']);
        $this->assertSame(2, $escritas[0]['users'], 'La copia va a toda la audiencia con cuenta.');
        // El contador de la llamada cuenta gente EMPUJADA, y no hubo ninguna: es el
        // número que se mira para decidir si escalar, y una copia esperando en la
        // web no es haber pedido ayuda a nadie.
        $this->assertSame(0, $call->getRecipients());
    }

    /**
     * El caso de verdad en que no hay nada que hacer: nadie con cuenta (ni push ni
     * bandeja) y nadie que quiera el correo. Ahí no se registra, para que el
     * alcance siga disponible si más adelante entra gente que sí encaje.
     */
    public function testSinNadieAQuienAvisarPorNingunaViaNoRegistra(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([]);

        $notifier = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1)]),
            users: $users,
            entityManager: $entityManager,
            preferences: $this->preferences([]),
        );

        $this->assertNull($notifier->dispatch(
            $this->offer(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        ));
    }

    /**
     * Si otro proceso ya registró esta misma llamada, la constancia existe y NO
     * se manda nada: repetir el aviso es exactamente lo que el UNIQUE evita.
     */
    public function testSiOtroProcesoSeAdelantoNoSeMandaNada(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(
            $this->createMock(UniqueConstraintViolationException::class)
        );

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $notifier = $this->notifier(
            audience: $this->audienceReturning([new Partner()]),
            entityManager: $entityManager,
            push: $push
        );

        $this->assertNull($notifier->dispatch(
            $this->offer(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        ));
    }

    /**
     * El camino feliz: se registra la llamada con el número real de
     * destinatarixs y se manda una sola vez, en lote.
     */
    public function testElCaminoFelizRegistraYMandaUnaVez(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([new User(), new User()]);

        // Con id: la cuenta de destinatarixs une las listas de correo y push
        // indexando por id, así que dos socixs sin persistir se colapsarían en
        // uno y el test mediría otra cosa.
        $notifier = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1), $this->partner(2)]),
            users: $users,
            entityManager: $entityManager,
            push: $push
        );

        $call = $notifier->dispatch(
            $this->offer(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        );

        $this->assertNotNull($call);
        $this->assertSame(2, $call->getRecipients());
        $this->assertFalse($call->isManual(), 'Sin persona detrás, la llamada es automática.');
    }

    /**
     * Preferencias dobladas: por defecto todo el mundo quiere todo, que es el
     * estado real de la asociación (sin fila de opt-out, el aviso se quiere).
     *
     * Estos casos comprueban a quién se le manda y cuándo, no la política de
     * preferencias, que tiene sus propios tests.
     *
     * @param list<Partner>|null $wanted quiénes lo quieren; null = todxs
     */
    private function preferences(?array $wanted = null): NotificationPreferences
    {
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('wants')->willReturn(true);
        // filter() es el que usan de verdad estos servicios —una consulta para
        // toda la lista en vez de una por socix—.
        $preferences->method('filter')->willReturnCallback(
            static fn (array $partners): array => $wanted ?? $partners
        );

        return $preferences;
    }

    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(3);
    }

    /**
     * Un socix con id, como los que salen de la base de datos.
     *
     * @param int $id el identificador a forzar
     */
    private function partner(int $id): Partner
    {
        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn($id);

        return $partner;
    }

    /**
     * @param list<Partner> $partners lxs socixs que devuelve
     */
    private function audienceReturning(array $partners): VolunteerAudienceResolver
    {
        $audience = $this->createMock(VolunteerAudienceResolver::class);
        $audience->method('resolve')->willReturn($partners);

        return $audience;
    }

    private function notifier(
        bool $enabled = true,
        ?VolunteerOfferRepository $offers = null,
        ?UserRepository $users = null,
        ?VolunteerAudienceResolver $audience = null,
        ?EntityManagerInterface $entityManager = null,
        ?PushSender $push = null,
        ?NotificationInbox $inbox = null,
        ?NotificationPreferences $preferences = null,
    ): VolunteerCallNotifier {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($enabled);

        $defaultUsers = $this->createMock(UserRepository::class);
        $defaultUsers->method('findByPartners')->willReturn([new User()]);

        $link = $this->createMock(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/panel/voluntariado');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/panel/voluntariado');

        return new VolunteerCallNotifier(
            $offers ?? $this->createMock(VolunteerOfferRepository::class),
            $users ?? $defaultUsers,
            $audience ?? $this->audienceReturning([]),
            $this->createMock(VolunteerCallEscalator::class),
            $push ?? $this->createMock(PushSender::class),
            $inbox ?? $this->createMock(NotificationInbox::class),
            $link,
            $preferences ?? $this->preferences(),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $settings,
            new VolunteerOfferFormatter(),
            new NullLogger(),
            $this->createMock(MailerInterface::class),
            $this->createMock(PartnerAccessPolicy::class),
            $urlGenerator
        );
    }
}
