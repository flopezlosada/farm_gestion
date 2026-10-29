<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\DeliveryConfirmationMailer;
use App\Service\Delivery\PickupNotice;
use App\Service\Delivery\PickupNoticePusher;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la vía común de avisos al móvil y en la bandeja, en lo que trae
 * la confirmación de la cesta y el recordatorio no tenía: avisar a quien NO
 * recoge. El resto (lotes, cuentas, idempotencia, preferencias) lo cubre
 * {@see PickupReminderPusherTest} a través del adaptador del recordatorio.
 */
class PickupNoticePusherTest extends TestCase
{
    /**
     * Quien movió su cesta a otra semana recibe que esta semana no recoge, y
     * SIN el sitio: "viernes 3 · Torremocha" a quien no tiene que ir se lee
     * como lo contrario.
     */
    public function testAQuienNoRecogeLeConfirmaQueSuCambioSeAplico(): void
    {
        $capturado = null;
        $push = $this->createMock(PushSender::class);
        $push->expects($this->once())->method('sendToMany')->willReturnCallback(
            static function (array $users, string $title, ?string $body, string $path, string $kind) use (&$capturado): int {
                $capturado = ['title' => $title, 'body' => $body, 'kind' => $kind];

                return \count($users);
            }
        );

        $result = $this->pusher($push)->send(
            [$this->notice(null)],
            DeliveryConfirmationMailer::EFFECT_KIND_PUSH,
            DeliveryConfirmationMailer::EFFECT_KIND,
        );

        $this->assertSame(1, $result['sent']);
        $this->assertSame('Esta semana no tienes recogida', $capturado['title']);
        $this->assertSame('Tu cambio está aplicado: el viernes 3 no recoges', $capturado['body']);
        $this->assertStringNotContainsString('Torremocha', $capturado['body']);
        $this->assertSame(DeliveryConfirmationMailer::EFFECT_KIND, $capturado['kind'], 'En la bitácora se llama como su correo.');
    }

    /**
     * La copia de la bandeja lleva el mismo texto que el push, y quien recoge lo
     * lee con el día y el sitio.
     */
    public function testLaBandejaDiceLoMismoQueElMovil(): void
    {
        $capturado = null;
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects($this->once())->method('deliver')->willReturnCallback(
            static function (array $recipients, string $kind, string $title, ?string $body) use (&$capturado): int {
                $capturado = ['kind' => $kind, 'title' => $title, 'body' => $body];

                return \count($recipients);
            }
        );

        $result = $this->pusher($this->createMock(PushSender::class), $inbox)->recordInbox(
            [$this->notice('de verduras')],
            DeliveryConfirmationMailer::EFFECT_KIND_INBOX,
        );

        $this->assertSame(1, $result['written']);
        $this->assertSame(Notification::KIND_PICKUP_REMINDER, $capturado['kind']);
        $this->assertStringEndsWith('recoges tu cesta de verduras', $capturado['title']);
        $this->assertSame('viernes 3 · Torremocha', $capturado['body']);
    }

    /**
     * Un aviso del viernes 3 de julio de 2099 en Torremocha, de un socix con cuenta.
     *
     * @param string|null $contents lo que recoge, o null si no recoge
     */
    private function notice(?string $contents): PickupNotice
    {
        return new PickupNotice($this->partner(), $contents, new \DateTimeImmutable('2099-07-03'), 'Torremocha', false);
    }

    private function partner(): Partner
    {
        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn(1);

        return $partner;
    }

    private function pusher(PushSender $push, ?NotificationInbox $inbox = null): PickupNoticePusher
    {
        $user = $this->createMock(User::class);
        $user->method('getPartner')->willReturn($this->partner());

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([$user]);

        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturnCallback(
            static function (string $kind, string $reference, \DateTimeInterface $on, callable $effect): bool {
                $effect();

                return true;
            }
        );

        $link = $this->createMock(NotificationLink::class);
        $link->method('pathForKind')->willReturn('/panel');

        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('filter')->willReturnArgument(0);

        return new PickupNoticePusher(
            $push,
            $users,
            $ledger,
            $inbox ?? $this->createMock(NotificationInbox::class),
            $link,
            $preferences,
        );
    }
}
