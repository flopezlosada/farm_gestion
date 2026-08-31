<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Repository\UserRepository;
use App\Service\Push\PushSender;
use App\Service\Volunteering\VolunteerOfferChangeNotifier;
use App\Entity\Notification;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Volunteering\VolunteerOfferFormatter;
use App\Service\Volunteering\VolunteerOfferSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se avisa a quien ya está apuntadx de que su tarea ha cambiado.
 *
 * Dos errores posibles y muy distintos. No avisar de una anulación deja a
 * alguien plantándose allí para nada, y esa persona no vuelve. Avisar de que han
 * corregido una falta de ortografía hace que el siguiente aviso, el que sí
 * importa, ya no lo lea nadie. Los dos se prueban aquí.
 */
class VolunteerOfferChangeNotifierTest extends TestCase
{
    /**
     * Anular la tarea avisa. Es el caso que justifica todo esto.
     */
    public function testAnularLaTareaAvisaAQuienSeApunto(): void
    {
        $offer = $this->offerWithOneSignup();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $push = $this->expectPushWithTitle('Se ha anulado una tarea');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Moverla de fecha también.
     */
    public function testMoverLaFechaAvisa(): void
    {
        $offer = $this->offerWithOneSignup();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStartsAt(new \DateTime('2099-03-16 17:00'));

        $push = $this->expectPushWithTitle('Cambia una tarea a la que te apuntaste');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Y cambiarla de sitio.
     */
    public function testCambiarDeSitioAvisa(): void
    {
        $offer = $this->offerWithOneSignup();
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setPlace('La nave');

        $push = $this->expectPushWithTitle('Cambia una tarea a la que te apuntaste');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Corregir el título NO avisa. Es la mitad que se olvida: un módulo que
     * manda un push por cada retoque acaba silenciado, y entonces el aviso de
     * la anulación tampoco llega.
     */
    public function testCorregirElTituloNoAvisa(): void
    {
        $offer = $this->offerWithOneSignup();
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
        $offer = $this->offerWithOneSignup();
        $before = VolunteerOfferSnapshot::of($offer);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * Un borrador que se mueve no molesta a nadie: nadie cuenta con él.
     */
    public function testMoverUnBorradorNoAvisa(): void
    {
        $offer = $this->offerWithOneSignup();
        $offer->setStatus(VolunteerOffer::STATUS_DRAFT);
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStartsAt(new \DateTime('2099-03-20 17:00'));

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * A quien se dio de baja no se le avisa: ya dijo que no iba, y recordárselo
     * es ruido.
     */
    public function testAQuienSeDioDeBajaNoSeLeAvisa(): void
    {
        $offer = $this->offer();
        $offer->addSignup((new VolunteerSignup())->setPartner(new Partner())->cancel());
        $before = VolunteerOfferSnapshot::of($offer);

        $offer->setStatus(VolunteerOffer::STATUS_CANCELLED);

        $push = $this->createMock(PushSender::class);
        $push->expects($this->never())->method('sendToMany');

        $this->assertSame(0, $this->notifier($push)->notifyChanges($offer, $before));
    }

    /**
     * La foto del estado anterior tiene que sobrevivir a que el formulario mute
     * el mismo objeto DateTime. Si guardara la referencia en vez de una copia,
     * un cambio de fecha no se detectaría nunca.
     */
    public function testLaFotoNoSeMueveConLaEntidad(): void
    {
        $startsAt = new \DateTime('2099-03-15 17:00');
        $offer = $this->offerWithOneSignup()->setStartsAt($startsAt);
        $before = VolunteerOfferSnapshot::of($offer);

        // Lo que hace el DateTimeType de Symfony al reutilizar el objeto.
        $startsAt->modify('+1 day');

        $push = $this->expectPushWithTitle('Cambia una tarea a la que te apuntaste');

        $this->assertSame(1, $this->notifier($push)->notifyChanges($offer, $before));
    }

    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setNode(new Node())
            ->setSlots(4);
    }

    private function offerWithOneSignup(): VolunteerOffer
    {
        $offer = $this->offer();
        $offer->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        return $offer;
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

    /** La bandeja doblada, cuando un caso quiere inspeccionarla. */
    private ?NotificationInbox $inbox = null;

    /**
     * LA COPIA EN LA BANDEJA ES EL ARREGLO DE ESTE SERVICIO. Salía sólo por push,
     * así que quien no lo tenía activado en ningún navegador —la mayoría— no se
     * enteraba de que su tarea se había anulado: justo el silencio que el docblock
     * de la clase llama "peor que no tener módulo".
     */
    public function testAnularLaTareaDejaCopiaEnLaBandeja(): void
    {
        $offer = $this->offerWithOneSignup();
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
        $offer = $this->offerWithOneSignup();
        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setTitle('Descargar el reparto (corregido)');

        $this->inbox = $this->createMock(NotificationInbox::class);
        $this->inbox->expects($this->never())->method('deliver');

        $this->assertSame(0, $this->notifier($this->createMock(PushSender::class))->notifyChanges($offer, $before));
    }
}
