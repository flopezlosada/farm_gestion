<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Node;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerPlace;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Push\PushSender;
use App\Service\Volunteering\VolunteerOfferChangeNotifier;
use App\Service\Volunteering\VolunteerOfferFormatter;
use App\Service\Volunteering\VolunteerOfferSnapshot;
use App\Service\Volunteering\VolunteerShiftSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se avisa a quien ya está apuntadx de que su trabajo ha cambiado.
 *
 * Dos errores posibles y muy distintos. No avisar de una anulación deja a
 * alguien plantándose allí para nada, y esa persona no vuelve. Avisar de que han
 * corregido una falta de ortografía hace que el siguiente aviso, el que sí
 * importa, ya no lo lea nadie. Los dos se prueban aquí.
 *
 * Y DOS PUERTAS, porque hay dos cosas que cambian: editar la TAREA (el sitio, o
 * pararla) afecta a todo el mundo con un turno por venir; mover o anular UN
 * TURNO afecta sólo a quien iba ese día. Mandar el aviso de "cambia de sitio" a
 * los doscientos apuntados de un año de tarea sería ruido hasta que nadie lea
 * ninguno.
 */
class VolunteerOfferChangeNotifierTest extends TestCase
{
    /** La bandeja doblada, cuando un caso quiere inspeccionarla. */
    private ?NotificationInbox $inbox = null;

    /**
     * Anular la tarea avisa. Es el caso que justifica todo esto.
     */
    public function testAnularLaTareaAvisaAQuienSeApunto(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $push = $this->expectPushWithTitle('Se ha anulado una tarea');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * PARARLA TAMBIÉN AVISA, y es un caso nuevo: quien tenía apuntado el sábado
     * que viene necesita saber que ese sábado no se hace. Es más suave que
     * anular, pero el silencio deja a alguien yendo igual.
     */
    public function testPararLaTareaAvisaAQuienTeniaTurnosPorVenir(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStatus(VolunteerOffer::STATUS_PAUSED);

        $push = $this->expectPushWithTitle('Se ha parado una tarea');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Cambiarla de sitio avisa a todo el mundo que tenga un turno por venir: el
     * sitio es de la tarea, así que el cambio les afecta a todos.
     */
    public function testCambiarDeSitioAvisa(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        // Con id, porque la foto compara ids: un sitio sin persistir tiene el id
        // a null y el cambio no se detectaría. En la aplicación el sitio siempre
        // viene de la base de datos.
        $offer->setPlace($this->place(3, 'La nave'));

        $push = $this->expectPushWithTitle('Cambia una tarea a la que te apuntaste');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Y la precisión sobre el sitio cuenta como cambio de sitio: "por la puerta
     * de atrás" es exactamente el tipo de dato por el que alguien se planta en
     * el lado equivocado.
     */
    public function testCambiarLaPrecisionDelSitioAvisa(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setPlaceNote('por la puerta de atrás');

        $push = $this->expectPushWithTitle('Cambia una tarea a la que te apuntaste');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * A QUIEN TENÍA UN TURNO YA ANULADO TAMPOCO SE LE AVISA: se le avisó cuando
     * se anuló ese día, y repetirlo al anular la tarea entera es contarle dos
     * veces lo mismo.
     *
     * Es la otra mitad del fallo que arregló esto: el destinatario se decide por
     * la anulación PROPIA del turno, no por la que hereda de la tarea — si se
     * mirara la heredada, anular la tarea escondería sus turnos y el aviso de la
     * anulación no llegaría a nadie.
     */
    public function testAQuienTeniaUnTurnoYaAnuladoNoSeLeAvisaOtraVez(): void
    {
        $shift = $this->shiftWithOneSignup();
        $shift->cancel('festivo');

        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * A QUIEN SÓLO TIENE TURNOS PASADOS NO SE LE AVISA. Es la regla que hace
     * usable una tarea continua: a quien vino en mayo no le importa que en
     * septiembre se cambie el sitio, y avisarle sería mandarle un push por algo
     * que ya hizo.
     */
    public function testNoSeAvisaAQuienSoloTieneTurnosPasados(): void
    {
        $offer = $this->offer();

        $pasado = (new VolunteerShift())->setStartsAt(new \DateTime('2020-03-15 17:00'));
        $offer->addShift($pasado);
        $pasado->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setPlaceNote('otro sitio');

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Corregir el título NO avisa. Es la mitad que se olvida: un módulo que
     * manda un push por cada retoque acaba silenciado, y entonces el aviso de
     * la anulación tampoco llega.
     */
    public function testCorregirElTituloNoAvisa(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setTitle('Descargar el reparto (corregido)');
        $offer->setDescription('Con más detalle');
        $offer->setSlots(8);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Guardar sin tocar nada tampoco avisa.
     */
    public function testGuardarSinCambiosNoAvisa(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Mover UN turno de fecha avisa a quien iba ese día, y dice las dos fechas:
     * sin la de antes, quien lo lee no sabe cuál de sus días ha cambiado.
     */
    public function testMoverUnTurnoAvisaAQuienIbaEseDia(): void
    {
        $shift = $this->shiftWithOneSignup();
        $before = VolunteerShiftSnapshot::of($shift);

        $shift->setStartsAt(new \DateTime('2099-03-16 17:00'));

        $push = $this->expectPushWithTitle('Cambia la fecha de un turno');

        $this->assertSame(1, $this->notifier($push)->notifyShiftChanges($shift, $before));
    }

    /**
     * Anular UN turno —el festivo— avisa sólo a quien iba ese día.
     */
    public function testAnularUnTurnoAvisaSoloAQuienIbaEseDia(): void
    {
        $shift = $this->shiftWithOneSignup();
        $before = VolunteerShiftSnapshot::of($shift);

        $shift->cancel('festivo');

        $push = $this->expectPushWithTitle('Se ha anulado un turno');

        $this->assertSame(1, $this->notifier($push)->notifyShiftChanges($shift, $before));
    }

    /**
     * Un turno de un borrador que se mueve no molesta a nadie: nadie cuenta con
     * él.
     */
    public function testMoverElTurnoDeUnBorradorNoAvisa(): void
    {
        $shift = $this->shiftWithOneSignup();
        $shift->getOffer()->setStatus(VolunteerOffer::STATUS_DRAFT);
        $before = VolunteerShiftSnapshot::of($shift);

        $shift->setStartsAt(new \DateTime('2099-03-20 17:00'));

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyShiftChanges($shift, $before));
    }

    /**
     * A quien se dio de baja no se le avisa: ya dijo que no iba, y recordárselo
     * es ruido.
     */
    public function testAQuienSeDioDeBajaNoSeLeAvisa(): void
    {
        $shift = $this->shift();
        $shift->addSignup((new VolunteerSignup())->setPartner(new Partner())->cancel());
        $before = VolunteerOfferSnapshot::of($shift->getOffer());

        $shift->getOffer()->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($shift->getOffer(), $before));
    }

    /**
     * La foto del estado anterior tiene que sobrevivir a que el formulario mute
     * el mismo objeto DateTime. Si guardara la referencia en vez de una copia,
     * un cambio de fecha no se detectaría nunca.
     */
    public function testLaFotoNoSeMueveConLaEntidad(): void
    {
        $startsAt = new \DateTime('2099-03-15 17:00');
        $shift = $this->shiftWithOneSignup()->setStartsAt($startsAt);
        $before = VolunteerShiftSnapshot::of($shift);

        // Lo que hace el DateTimeType de Symfony al reutilizar el objeto.
        $startsAt->modify('+1 day');

        $push = $this->expectPushWithTitle('Cambia la fecha de un turno');

        $this->assertSame(1, $this->notifier($push)->notifyShiftChanges($shift, $before));
    }

    /**
     * LA COPIA EN LA BANDEJA ES EL ARREGLO DE ESTE SERVICIO. Salía sólo por push,
     * así que quien no lo tenía activado en ningún navegador —la mayoría— no se
     * enteraba de que su tarea se había anulado: justo el silencio que el docblock
     * de la clase llama "peor que no tener módulo".
     */
    public function testAnularLaTareaDejaCopiaEnLaBandeja(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $escritas = [];
        $this->inbox = $this->createMock(NotificationInbox::class);
        $this->inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title, ?string $body) use (&$escritas): int {
                $escritas[] = ['kind' => $kind, 'title' => $title, 'body' => $body];

                return \count($users);
            }
        );

        $this->notifier($this->createMock(PushSender::class))->notifyChanges($offer, $before);

        $this->assertCount(1, $escritas);
        $this->assertSame(Notification::KIND_VOLUNTEERING_CHANGE, $escritas[0]['kind']);
        $this->assertSame('Se ha anulado una tarea', $escritas[0]['title']);
        $this->assertStringContainsString('Ya no hace falta que vayas', (string) $escritas[0]['body']);
    }

    /**
     * Un cambio que no merece aviso no deja copia tampoco: la bandeja no puede
     * llenarse de "se ha guardado la tarea".
     */
    public function testUnCambioQueNoAvisaTampocoDejaCopia(): void
    {
        $shift = $this->shiftWithOneSignup();
        $offer = $shift->getOffer();
        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setTitle('Descargar el reparto (corregido)');

        $this->inbox = $this->createMock(NotificationInbox::class);
        $this->inbox->expects($this->never())->method('deliver');

        $this->assertSame(0, $this->notifier($this->createMock(PushSender::class))->notifyChanges($offer, $before));
    }

    /**
     * Una tarea publicada con nodo y plazas, sin turnos.
     */
    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setNode(new Node())
            ->setSlots(4);
    }

    /**
     * Un sitio del catálogo con id, como los que salen de la base de datos.
     *
     * @param int    $id   el identificador a forzar
     * @param string $name su nombre
     */
    private function place(int $id, string $name): VolunteerPlace
    {
        $place = (new VolunteerPlace())->setName($name);

        $reflection = new \ReflectionProperty(VolunteerPlace::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($place, $id);

        return $place;
    }

    /**
     * Un turno futuro de esa tarea.
     */
    private function shift(): VolunteerShift
    {
        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2099-03-15 17:00'));
        $this->offer()->addShift($shift);

        return $shift;
    }

    /**
     * Un turno futuro con una persona apuntada.
     */
    private function shiftWithOneSignup(): VolunteerShift
    {
        $shift = $this->shift();
        $shift->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        return $shift;
    }

    /**
     * @param string $title el título que debe llevar el aviso
     */
    private function expectPushWithTitle(string $title): PushSender
    {
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())
            ->method('sendToMany')
            ->with($this->anything(), $title, $this->anything(), '/panel/voluntariado');

        return $push;
    }

    private function notifier(PushSender $push): VolunteerOfferChangeNotifier
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([new User()]);

        $link = $this->createMock(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/panel/voluntariado');

        return new VolunteerOfferChangeNotifier(
            $users,
            $push,
            $this->inbox ?? $this->createMock(NotificationInbox::class),
            $link,
            new VolunteerOfferFormatter(),
        );
    }
}
