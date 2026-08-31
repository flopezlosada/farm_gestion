<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\DeliveryDeadline;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupReminderMailer;
use App\Service\Delivery\PickupReminderPusher;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del aviso de recogida al móvil. Lo que se protege aquí es que el
 * push diga lo MISMO que el correo (misma fecha física, mismo nodo), que no se
 * mande de uno en uno, y que quien no tiene cuenta de acceso no rompa nada.
 *
 * Usa un {@see PickupReminderMailer} real y no un doble: la promesa del módulo
 * es que los dos canales no puedan divergir, y con el contexto mockeado el test
 * pasaría aunque el push inventara su propia fecha.
 *
 * Cubre además la COPIA DE LA BANDEJA ({@see PickupReminderPusher::recordInbox()}),
 * y la asimetría que la define: el push respeta las preferencias del socix y la
 * copia NO. Es la promesa que la pantalla de avisos hace por escrito —lo apagado
 * "sigue estando en tu bandeja"—, así que tiene test propio en las dos
 * direcciones.
 */
class PickupReminderPusherTest extends TestCase
{
    /**
     * Un solo lote por (día, nodo): el mensaje nombra el sitio y el día, así que
     * dos nodos distintos son dos textos distintos — pero dentro de un nodo, por
     * mucha gente que haya, se manda una sola vez.
     */
    public function testAgrupaPorMensajeYMandaUnLotePorGrupo(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03'); // viernes

        $sierraUno = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);
        $sierraDos = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 2);
        $madrid = $this->weeklyBasket($this->node('Cascorro', 5), $friday, 3);

        $envios = [];
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users, string $title, ?string $body) use (&$envios): int {
                $envios[] = ['users' => \count($users), 'title' => $title, 'body' => $body];

                return \count($users);
            }
        );

        $result = $this->pusher($push, [1, 2, 3])->send([$sierraUno, $sierraDos, $madrid]);

        $this->assertCount(2, $envios, 'Dos nodos, dos lotes; los dos de Torremocha viajan juntos.');
        $this->assertSame(3, $result['sent']);
        $this->assertSame(3, $result['devices']);

        $porNodo = [];
        foreach ($envios as $envio) {
            $porNodo[$envio['body']] = $envio['users'];
        }
        $this->assertSame(2, $porNodo['viernes 3 · Torremocha']);
        $this->assertSame(1, $porNodo['viernes 3 · Cascorro']);
    }

    /**
     * El desplazamiento por festivo es lo primero que se lee: quien va en
     * automático el día de siempre es justo a quien hay que avisar.
     */
    public function testAvisaDelDesplazamientoCuandoNoEsElDiaHabitual(): void
    {
        $thursday = new \DateTimeImmutable('2099-07-02');
        // El nodo reparte los viernes; esta entrega cae en jueves.
        $wb = $this->weeklyBasket($this->node('Torremocha', 5), $thursday, 1);

        $capturado = null;
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users, string $title, ?string $body) use (&$capturado): int {
                $capturado = $body;

                return 1;
            }
        );

        $this->pusher($push, [1])->send([$wb]);

        $this->assertStringStartsWith('OJO, no es el día habitual', (string) $capturado);
        $this->assertStringContainsString('jueves 2', (string) $capturado);
    }

    /**
     * Quien no tiene cuenta de acceso no recibe push y tampoco cuenta como
     * avisado: el correo le llega igual, y apuntarlo aquí como enviado dejaría
     * el apunte mintiendo si algún día se crea la cuenta.
     */
    public function testIgnoraAQuienNoTieneCuentaDeAcceso(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $conCuenta = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);
        $sinCuenta = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 2);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')->willReturn(1);

        // Sólo el socix 1 tiene User.
        $result = $this->pusher($push, [1])->send([$conCuenta, $sinCuenta]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['already']);
    }

    /**
     * Segunda pasada del reloj: el apunte del guardián ya existe y no se manda
     * nada. Sin esto, el reloj horario avisaría a la misma gente cada hora.
     */
    public function testNoReenviaLoQueYaConstaEmitido(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $wb = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $result = $this->pusher($push, [1], $this->ledger(alreadyEmitted: true))->send([$wb]);

        $this->assertSame(['sent' => 0, 'devices' => 0, 'already' => 1], $result);
    }

    /**
     * El título habla en el idioma de quien lo lee: "hoy" y "mañana" antes que
     * el nombre del día, que a dos días vista no sitúa a nadie.
     */
    public function testTituloDiceHoyYManana(): void
    {
        $hoy = new \DateTimeImmutable('today');
        $manana = $hoy->modify('+1 day');
        $pasado = $hoy->modify('+2 days');

        $this->assertSame('Hoy recoges tu cesta', $this->tituloPara($hoy));
        $this->assertSame('Mañana recoges tu cesta', $this->tituloPara($manana));
        $this->assertStringStartsWith('El ', $this->tituloPara($pasado));
    }

    /**
     * LA COPIA DE LA BANDEJA SE ESCRIBE AUNQUE EL SOCIX HAYA APAGADO EL MÓVIL, y
     * es la promesa entera de la campanita: la pantalla de preferencias dice que
     * lo apagado "sigue estando en tu bandeja", y si este test se rompe esa frase
     * pasa a ser mentira para justo quien no tiene otra vía.
     */
    public function testLaCopiaDeLaBandejaSeEscribeIgnorandoLasPreferencias(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $wb = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);

        $escritas = [];
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title, ?string $body) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'body' => $body];

                return \count($users);
            }
        );

        // Preferencias que NO dejan pasar a nadie por push.
        $pusher = $this->pusher($this->createMock(PushSender::class), [1], null, $inbox, $this->preferences([]));

        $result = $pusher->recordInbox([$wb]);

        $this->assertSame(['written' => 1, 'already' => 0], $result);
        $this->assertCount(1, $escritas);
        $this->assertSame(Notification::KIND_PICKUP_REMINDER, $escritas[0]['kind']);
        // El mismo texto corto que el push: fecha física y nodo, no un genérico.
        $this->assertSame('viernes 3 · Torremocha', $escritas[0]['body']);
    }

    /**
     * Quien no tiene cuenta de acceso no tiene bandeja donde mirar, así que no se
     * le escribe nada: sería una fila que nadie puede abrir.
     */
    public function testNoDejaCopiaAQuienNoTieneCuentaDeAcceso(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $sinCuenta = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 7);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        // El repositorio sólo devuelve cuentas del socix 1; el 7 no tiene.
        $result = $this->pusher($this->createMock(PushSender::class), [1], null, $inbox)->recordInbox([$sinCuenta]);

        $this->assertSame(['written' => 0, 'already' => 0], $result);
    }

    /**
     * Segunda pasada del reloj: la copia ya consta escrita y no se repite. Sin su
     * propio apunte, el barrido horario dejaría veinticuatro filas idénticas en la
     * bandeja de cada socix.
     */
    public function testNoRepiteLaCopiaYaEscrita(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $wb = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->never())->method('deliver');

        $result = $this->pusher(
            $this->createMock(PushSender::class),
            [1],
            $this->ledger(alreadyEmitted: true),
            $inbox,
        )->recordInbox([$wb]);

        $this->assertSame(['written' => 0, 'already' => 1], $result);
    }

    /**
     * El push SÍ respeta la preferencia, al contrario que la bandeja: quien ha
     * dicho que no quiere el aviso en el móvil no lo recibe en el móvil.
     */
    public function testElPushRespetaLaPreferenciaAunqueLaBandejaNo(): void
    {
        $friday = new \DateTimeImmutable('2099-07-03');
        $wb = $this->weeklyBasket($this->node('Torremocha', 5), $friday, 1);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $result = $this->pusher($push, [1], null, null, $this->preferences([]))->send([$wb]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['devices']);
    }

    /**
     * El título que sale para una fecha dada, mandando un aviso de mentira.
     *
     * @param \DateTimeImmutable $date la fecha física de recogida
     *
     * @return string el título del aviso
     */
    private function tituloPara(\DateTimeImmutable $date): string
    {
        $wb = $this->weeklyBasket($this->node('Torremocha', (int) $date->format('N')), $date, 1);

        $capturado = '';
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturnCallback(
            static function (array $users, string $title) use (&$capturado): int {
                $capturado = $title;

                return 1;
            }
        );

        $this->pusher($push, [1])->send([$wb]);

        return $capturado;
    }

    /**
     * El pusher con sus dependencias dobladas.
     *
     * @param PushSender        $push          el enviador
     * @param list<int>         $conCuenta     ids de socix que tienen cuenta de acceso
     * @param EffectLedger|null $ledger        el guardián de idempotencia
     */
    private function pusher(
        PushSender $push,
        array $conCuenta,
        ?EffectLedger $ledger = null,
        ?NotificationInbox $inbox = null,
        ?NotificationPreferences $preferences = null,
    ): PickupReminderPusher {
        $users = [];
        foreach ($conCuenta as $partnerId) {
            $partner = $this->createMock(Partner::class);
            $partner->method('getId')->willReturn($partnerId);

            $user = $this->createMock(User::class);
            $user->method('getPartner')->willReturn($partner);
            $users[] = $user;
        }

        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByPartners')->willReturn($users);

        $link = $this->createMock(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/panel');

        return new PickupReminderPusher(
            $push,
            $repository,
            $this->mailer(),
            $ledger ?? $this->ledger(),
            $inbox ?? $this->createMock(NotificationInbox::class),
            $link,
            $preferences ?? $this->preferences(),
        );
    }

    /**
     * Preferencias que dejan pasar a todo el mundo, que es el estado por defecto
     * de la asociación: sin fila de opt-out, el aviso se quiere.
     *
     * @param list<Partner>|null $wanted quiénes quieren el push; null = todxs
     */
    private function preferences(?array $wanted = null): NotificationPreferences
    {
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('filter')->willReturnCallback(
            static fn (array $partners): array => $wanted ?? $partners
        );

        return $preferences;
    }

    /**
     * El mailer REAL, sólo para su contextFor(): es la garantía de que push y
     * correo hablan de la misma fecha y el mismo nodo.
     */
    private function mailer(): PickupReminderMailer
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn(false);
        $settings->method('getInt')->willReturn(1);
        $settings->method('getTime')->willReturn('20:59');

        // DeliveryDeadline es final: instancia real. fromPhysicalDate() sólo
        // consume $settings.
        $deadline = new DeliveryDeadline($this->createMock(NodeDeliveryDate::class), $settings);

        // Todo el mundo quiere el aviso: aquí se comprueba el push, no la
        // política de preferencias, que tiene sus propios tests.
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('wants')->willReturn(true);
        // filter() es el que usan de verdad estos servicios —una consulta para
        // toda la lista en vez de una por socix—: devuelve a todo el mundo.
        $preferences->method('filter')->willReturnArgument(0);

        return new PickupReminderMailer(
            $this->createMock(MailerInterface::class),
            $settings,
            $this->createMock(PartnerAccessPolicy::class),
            $this->createMock(UrlGeneratorInterface::class),
            $deadline,
            $this->ledger(),
            $preferences,
        );
    }

    /**
     * Guardián de doble uso: por defecto ejecuta el efecto (como el real la
     * primera vez); con $alreadyEmitted no ejecuta nada.
     *
     * @param bool $alreadyEmitted ¿el efecto ya constaba emitido?
     */
    private function ledger(bool $alreadyEmitted = false): EffectLedger
    {
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturnCallback(
            static function (string $kind, string $reference, \DateTimeInterface $on, callable $effect) use ($alreadyEmitted): bool {
                if ($alreadyEmitted) {
                    return false;
                }

                $effect();

                return true;
            }
        );

        return $ledger;
    }

    private function node(string $name, int $deliveryWeekday): Node
    {
        return (new Node())->setName($name)->setDeliveryWeekday($deliveryWeekday);
    }

    /**
     * Una cesta materializada de un socix con id, que es lo que exige la clave
     * de idempotencia.
     *
     * @param Node               $node         el nodo de recogida
     * @param \DateTimeImmutable $deliveryDate la fecha física
     * @param int                $partnerId    el id del socix
     */
    private function weeklyBasket(Node $node, \DateTimeImmutable $deliveryDate, int $partnerId): WeeklyBasket
    {
        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn($partnerId);

        $share = $this->createMock(BasketShare::class);
        $share->method('getId')->willReturn(BasketShare::ID_BIWEEKLY);

        return (new WeeklyBasket())
            ->setPartner($partner)
            ->setWeeklyBasketGroup((new WeeklyBasketGroup())->setName($node->getName())->setNode($node))
            ->setBasketShare($share)
            ->setDeliveryDate($deliveryDate);
    }
}
