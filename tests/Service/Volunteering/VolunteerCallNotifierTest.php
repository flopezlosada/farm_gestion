<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\UserRepository;
use App\Repository\VolunteerShiftRepository;
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
        $shifts = $this->createMock(VolunteerShiftRepository::class);
        $shifts->expects($this->never())->method('findUpcoming');

        $notifier = $this->notifier(enabled: false, shifts: $shifts);

        $this->assertSame(0, $notifier->dispatchDue(new \DateTimeImmutable('2099-03-01 10:00')));
    }

    /**
     * Sin nadie a quien avisar no se registra la llamada: así el alcance sigue
     * disponible si más adelante entra gente que sí encaje. Registrarla gastaría
     * el UNIQUE (shift, scope) por un aviso que no salió.
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
            $this->shift(),
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
        $destinatariosBandeja = [];
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users) use (&$destinatariosPush): int {
                $destinatariosPush = $users;

                return 0;
            }
        );

        // Y tampoco copia en la bandeja: sin cuenta no hay bandeja donde mirar, y
        // escribirla sería una fila que nadie puede abrir. Se comprueba a QUIÉN
        // llega y no si se llama al servicio, igual que con el push de arriba:
        // afirmar que no se le llama ataría el test a un detalle (deliver() ya
        // sabe no hacer nada con la lista vacía).

        $notifier = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1)]),
            users: $users,
            entityManager: $entityManager,
            push: $push,
            inbox: $this->recordingInbox($destinatariosBandeja)
        );

        $call = $notifier->dispatch(
            $this->shift(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        );

        $this->assertNotNull($call, 'Se registra la llamada: al socix se le avisa por correo.');
        $this->assertSame(1, $call->getRecipients());
        $this->assertSame([], $destinatariosPush ?? [], 'Sin cuenta no hay push, sólo correo.');
        $this->assertSame([], $destinatariosBandeja, 'Sin cuenta tampoco hay bandeja donde escribir.');
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

        // Igual que arriba: se comprueba a QUIÉN llega el push, no si se llama al
        // enviador. Con las preferencias apagadas el notificador le pasa la lista
        // vacía, y ahí no se manda nada.
        $destinatariosPush = null;
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users) use (&$destinatariosPush): int {
                $destinatariosPush = $users;

                return 0;
            }
        );

        $escritas = [];
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'users' => \count($users)];

                return \count($users);
            }
        );

        // El doble RESPETA EL ARGUMENTO, y aquí es imprescindible: con las
        // preferencias apagadas el notificador pide las cuentas de una lista
        // vacía, y un doble que devuelva gente igualmente haría que el push
        // pareciera tener a quien mandar. El repositorio real devuelve [].
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturnCallback(
            static fn (array $partners): array => [] === $partners ? [] : [new User(), new User()]
        );

        $call = $this->notifier(
            audience: $this->audienceReturning([$this->partner(1), $this->partner(2)]),
            users: $users,
            entityManager: $entityManager,
            push: $push,
            inbox: $inbox,
            // Nadie quiere el aviso por ningún canal configurable.
            preferences: $this->preferences([]),
        )->dispatch($this->shift(), VolunteerCall::SCOPE_MATCHING, null, new \DateTimeImmutable('2099-03-01 10:00'));

        $this->assertNotNull($call, 'La llamada se registra: el aviso salió, aunque sólo a la bandeja.');
        $this->assertSame([], $destinatariosPush ?? [], 'Con el push apagado no se empuja a nadie.');
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
            $this->shift(),
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
            $this->shift(),
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
            $this->shift(),
            VolunteerCall::SCOPE_MATCHING,
            null,
            new \DateTimeImmutable('2099-03-01 10:00')
        );

        $this->assertNotNull($call);
        $this->assertSame(2, $call->getRecipients());
        $this->assertFalse($call->isManual(), 'Sin persona detrás, la llamada es automática.');
    }

    /**
     * PEDÍRSELO A UNA PERSONA NO ESCRIBE NINGUNA LLAMADA, y es la invariante que
     * más importa de todo esto: el registro de {@see VolunteerCall} existe para
     * que el planificador no repita un aviso masivo, y su UNIQUE (turno,
     * alcance) es lo único que lo garantiza. Si pedírselo a Inés gastara ese
     * registro, el aviso automático a quien tiene marcada el área no saldría
     * nunca — y nadie se enteraría de por qué.
     */
    public function testPedirseloAUnaPersonaNoGastaElAvisoDeAmbito(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $ways = $this->notifier(entityManager: $em)->ask($this->shift(), $this->partner(7));

        $this->assertNotSame([], $ways, 'Con cuenta y preferencias abiertas, algo tiene que salir.');
    }

    /**
     * La copia en la bandeja es el suelo: quien tiene cuenta se entera al
     * entrar, aunque no se haya suscrito nunca a los avisos del navegador. Es lo
     * que hace que este botón sirva para casi toda la asociación y no sólo para
     * quien tiene push.
     */
    public function testSinPushLaPeticionLlegaIgualALaBandeja(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([new User()]);

        // Preferencias abiertas, pero ni un navegador suscrito: sendToMany
        // devuelve NAVEGADORES alcanzados, y cero significa que por ahí no llegó.
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturn(0);

        $ways = $this->notifier(users: $users, push: $push)
            ->ask($this->shift(), $this->partner(7));

        $this->assertContains('inbox', $ways);
        $this->assertNotContains('push', $ways, 'Sin navegador suscrito no se puede decir que le llegó al móvil.');
    }

    /**
     * Con un navegador suscrito sí se dice que le llegó al móvil. La distinción
     * no es cosmética: es lo que la pantalla le cuenta a quien acaba de pulsar,
     * y de ahí depende que llame por teléfono o se quede tranquilx.
     */
    public function testConNavegadorSuscritoLaPeticionLlegaAlMovil(): void
    {
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturn(1);

        $ways = $this->notifier(push: $push)->ask($this->shift(), $this->partner(7));

        $this->assertContains('push', $ways);
    }

    /**
     * Sin cuenta y sin correo no hay por dónde: ni bandeja, ni navegador que se
     * haya podido suscribir, ni dirección donde escribir. Hay que decirlo en vez
     * de fingir que se le pidió, o quien coordina no coge el teléfono.
     */
    public function testSinCuentaNiCorreoNoHayPorDondePedirselo(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([]);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $this->assertSame(
            [],
            $this->notifier(users: $users, push: $push, inbox: $inbox)
                ->ask($this->shift(), $this->partner(7))
        );
    }

    /**
     * 🔴 QUIEN PIDIÓ QUE NO SE LE AVISE NO RECIBE NADA, aunque alguien pulse el
     * botón con su nombre delante.
     *
     * Es el caso que justifica que este método compruebe el opt-out por su
     * cuenta en vez de fiarse de la pantalla. Son dos mecanismos distintos:
     * `Partner::volunteering_opt_out` es la columna que el socix marca en su
     * panel, y `NotificationOptOut` —lo único que leen las preferencias— es otra
     * tabla, fina por tema y canal. Quien usó el interruptor duro es justo quien
     * NO va a tener fila en la fina, así que confiar sólo en las preferencias
     * dejaba pasar el aviso a quien más claro lo había dicho.
     *
     * Y la lista de la pantalla no vale como garantía: la filtra un GET, pero el
     * POST recibe un id y basta con tener la ficha cargada de antes.
     */
    public function testAQuienPidioQueNoSeLeAviseNoSeLePide(): void
    {
        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $notifier = $this->notifier(push: $push, inbox: $inbox, mailer: $mailer);

        // Con correo en la ficha y cuenta en la web: todas las vías abiertas
        // menos la que importa.
        $this->assertSame(
            [],
            $notifier->ask($this->shift(), $this->partner(7, 'ines@example.org', optOut: true))
        );
    }

    /**
     * A quien se dio de baja tampoco se le pide ayuda. Sale de la misma familia
     * de invariantes que respetan los finders de los que sale la audiencia
     * automática, y aquí llegaba sin comprobar porque el id viene por la URL.
     */
    public function testAQuienSeDioDeBajaNoSeLePide(): void
    {
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $this->assertSame(
            [],
            $this->notifier(inbox: $inbox)
                ->ask($this->shift(), $this->partner(7, 'x@example.org', status: Partner::STATUS_BAJA))
        );
    }

    /**
     * PERO SIN CUENTA TODAVÍA PUEDE HABER CORREO, y es fácil confundirlo: la
     * bandeja y el push cuelgan de la cuenta de acceso, la dirección de correo
     * vive en la ficha del socix. Quien nunca ha entrado en la web pero tiene su
     * correo puesto sí se entera, igual que con el aviso de ámbito.
     */
    public function testSinCuentaPeroConCorreoSeLePuedePedirPorAhi(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([]);

        $ways = $this->notifier(users: $users)
            ->ask($this->shift(), $this->partner(7, 'ines@example.org'));

        $this->assertSame(['email'], $ways);
    }

    /**
     * Y con cuenta pero sin correo en la ficha NO se dice que le llega por
     * correo. `email()` salta en silencio a quien no tiene dirección, así que
     * contarlo como vía sería decirle a quien pulsa que el aviso salió por un
     * sitio por el que no salió.
     */
    public function testSinCorreoEnLaFichaNoSeCuentaEsaVia(): void
    {
        $ways = $this->notifier()->ask($this->shift(), $this->partner(7));

        $this->assertNotContains('email', $ways);
    }

    /**
     * Quien pidió que no se le avise por el móvil no recibe push porque alguien
     * se lo pida a mano. Pedírselo de persona a persona no es una puerta de
     * atrás a las preferencias de nadie.
     */
    public function testPedirseloRespetaLasPreferenciasDeCanal(): void
    {
        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $ways = $this->notifier(push: $push, preferences: $this->preferences([]))
            ->ask($this->shift(), $this->partner(7));

        $this->assertSame(['inbox'], $ways, 'Queda la bandeja, que no pasa por preferencias.');
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

    /**
     * Un turno futuro con plazas, de una tarea publicada. Se devuelve el TURNO
     * porque es lo que recibe el notificador: se pide gente para un día, no para
     * un trabajo en abstracto.
     */
    private function shift(): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(3);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2099-03-15 17:00'));
        $offer->addShift($shift);

        return $shift;
    }

    /**
     * Un socix con id, como los que salen de la base de datos.
     *
     * Sin correo por defecto, a propósito: uno de cada ocho socixs no tiene, y
     * conviene que el caso normal de los tests sea el que más se olvida.
     *
     * El estado se dobla explícitamente a ACTIVO: un mock sin stub devuelve la
     * cadena vacía, que NO es un estado válido, y entonces `canBeAsked()` diría
     * que a nadie se le puede pedir nada y los casos pasarían por el motivo
     * equivocado.
     *
     * @param int         $id      el identificador a forzar
     * @param string|null $email   su dirección, si la tiene en la ficha
     * @param bool        $optOut  si ha pedido que no se le avise de voluntariado
     * @param string      $status  su estado como socix
     */
    private function partner(
        int $id,
        ?string $email = null,
        bool $optOut = false,
        string $status = Partner::STATUS_ACTIVO,
    ): Partner {
        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn($id);
        $partner->method('getEmail')->willReturn($email);
        $partner->method('isVolunteeringOptOut')->willReturn($optOut);
        $partner->method('getStatus')->willReturn($status);

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
        ?VolunteerShiftRepository $shifts = null,
        ?UserRepository $users = null,
        ?VolunteerAudienceResolver $audience = null,
        ?EntityManagerInterface $entityManager = null,
        ?PushSender $push = null,
        ?NotificationInbox $inbox = null,
        ?NotificationPreferences $preferences = null,
        ?MailerInterface $mailer = null,
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
            $shifts ?? $this->createMock(VolunteerShiftRepository::class),
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
            $mailer ?? $this->createMock(MailerInterface::class),
            $this->createMock(PartnerAccessPolicy::class),
            $urlGenerator
        );
    }

    /**
     * Una bandeja que apunta a CUÁNTAS cuentas se le pide escribir.
     *
     * @param list<int> $destinatarios donde se apunta el tamaño de cada lote
     */
    private function recordingInbox(array &$destinatarios): NotificationInbox
    {
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users) use (&$destinatarios): int {
                foreach ($users as $user) {
                    $destinatarios[] = $user;
                }

                return \count($users);
            }
        );

        return $inbox;
    }
}
