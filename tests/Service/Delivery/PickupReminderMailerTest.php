<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryDeadline;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupReminderMailer;
use App\Service\Notification\NotificationPreferences;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del constructor de emails del recordatorio de recogida. Verifica que
 * cada email habla de la FECHA FÍSICA y el NODO de ese socix (Madrid miércoles en
 * Cascorro, la Sierra viernes en Torremocha), que marca el desplazamiento cuando
 * el día no es el habitual del nodo, y que se envía uno por socix con email
 * saltando los que no tienen. Todo con dependencias mockeadas: no toca BBDD ni
 * envía nada real.
 */
class PickupReminderMailerTest extends TestCase
{
    public function testContextoReflejaFechaNodoYModalidad(): void
    {
        // Fecha cuyo día de la semana COINCIDE con el del nodo → no desplazado.
        $pickup = new \DateTimeImmutable('2099-07-01');
        $wb = $this->weeklyBasket(
            node: $this->node('Cascorro', (int) $pickup->format('N')),
            deliveryDate: $pickup,
            shareId: BasketShare::ID_BIWEEKLY,
            email: 'madrid@test.org',
        );

        $ctx = $this->mailer()->contextFor($wb);

        $this->assertSame('Cascorro', $ctx['node_name']);
        $this->assertEquals($pickup, $ctx['pickup_date']);
        $this->assertSame('quincenal', $ctx['modality']);
        $this->assertFalse($ctx['was_shifted'], 'Recogida en el día habitual del nodo: no está desplazada.');
    }

    public function testContextoMarcaDesplazadoCuandoElDiaNoEsElHabitual(): void
    {
        // El nodo reparte habitualmente el viernes; esta cesta cae en jueves (festivo).
        $thursday = new \DateTimeImmutable('2099-07-02');
        $habitualWeekday = ((int) $thursday->format('N')) % 7 + 1; // cualquier día distinto
        $wb = $this->weeklyBasket(
            node: $this->node('Torremocha', $habitualWeekday),
            deliveryDate: $thursday,
            shareId: BasketShare::ID_BIWEEKLY,
            email: 'sierra@test.org',
        );

        $ctx = $this->mailer()->contextFor($wb);

        $this->assertSame('Torremocha', $ctx['node_name']);
        $this->assertEquals($thursday, $ctx['pickup_date']);
        $this->assertTrue($ctx['was_shifted'], 'Recogida fuera del día habitual del nodo: desplazada.');
    }

    public function testSendEnviaUnoPorSocioConEmailYSaltaSinEmail(): void
    {
        $pickup = new \DateTimeImmutable('2099-07-01');
        $conEmail = $this->weeklyBasket($this->node('Cascorro', 3), $pickup, BasketShare::ID_BIWEEKLY, 'ok@test.org');
        $sinEmail = $this->weeklyBasket($this->node('Cascorro', 3), $pickup, BasketShare::ID_BIWEEKLY, null);

        $captured = [];
        $mailerMock = $this->createMock(MailerInterface::class);
        $mailerMock->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function (TemplatedEmail $email) use (&$captured): void {
                $captured[] = $email;
            });

        $result = $this->mailer($mailerMock)->send([$conEmail, $sinEmail]);

        $this->assertSame(['sent' => 1, 'skipped' => 1, 'already' => 0], $result);
        $this->assertSame('ok@test.org', $captured[0]->getTo()[0]->getAddress());
        $this->assertSame('email/pickup_reminder.html.twig', $captured[0]->getHtmlTemplate());
        $this->assertSame('email/pickup_reminder.txt.twig', $captured[0]->getTextTemplate());
        $this->assertStringNotContainsStringIgnoringCase('viernes', $captured[0]->getSubject(), 'El asunto ya no fija "viernes".');
    }

    /**
     * Un aviso que el guardián de idempotencia ya tenía apuntado no se reenvía y
     * se cuenta aparte: es el caso normal de una segunda pasada del reloj, y no
     * debe confundirse con "no había a quién avisar".
     */
    public function testNoReenviaLoQueYaConstaEmitido(): void
    {
        $pickup = new \DateTimeImmutable('2099-07-01');
        $wb = $this->weeklyBasket($this->node('Cascorro', 3), $pickup, BasketShare::ID_BIWEEKLY, 'ok@test.org');

        $mailerMock = $this->createMock(MailerInterface::class);
        $mailerMock->expects($this->never())->method('send');

        $result = $this->mailer($mailerMock, $this->ledger(alreadyEmitted: true))->send([$wb]);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'already' => 1], $result);
    }

    /**
     * Guardián de idempotencia de doble uso: por defecto deja pasar el efecto (y
     * lo ejecuta, como haría el real la primera vez); con $alreadyEmitted a true
     * simula una clave ya apuntada y NO ejecuta nada.
     *
     * @param bool $alreadyEmitted ¿El efecto ya constaba emitido?
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

    private function mailer(?MailerInterface $mailerMock = null, ?EffectLedger $ledger = null): PickupReminderMailer
    {
        // Links apagados: canUseActionLinks no se llega a invocar (corto-circuito).
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn(false);
        $settings->method('getInt')->willReturn(1);      // DEADLINE_DAYS_BEFORE
        $settings->method('getTime')->willReturn('20:59'); // DEADLINE_TIME

        // DeliveryDeadline es final: no se puede mockear. Se usa una instancia real;
        // fromPhysicalDate() solo consume $settings (no toca NodeDeliveryDate).
        $deadline = new DeliveryDeadline($this->createMock(NodeDeliveryDate::class), $settings);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://csavegadejarama.org/panel/calendario');

        // Todo el mundo quiere el aviso salvo que un test diga lo contrario:
        // estos casos comprueban a quién se le manda y qué dice, no la política
        // de preferencias, que tiene sus propios tests.
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('wants')->willReturn(true);
        // filter() es el que usan de verdad estos servicios —una consulta para
        // toda la lista en vez de una por socix—: devuelve a todo el mundo.
        $preferences->method('filter')->willReturnArgument(0);

        return new PickupReminderMailer(
            $mailerMock ?? $this->createMock(MailerInterface::class),
            $settings,
            $this->createMock(PartnerAccessPolicy::class),
            $urlGenerator,
            $deadline,
            $ledger ?? $this->ledger(),
            $preferences,
        );
    }

    private function node(string $name, int $deliveryWeekday): Node
    {
        return (new Node())->setName($name)->setDeliveryWeekday($deliveryWeekday);
    }

    private function weeklyBasket(Node $node, \DateTimeImmutable $deliveryDate, int $shareId, ?string $email): WeeklyBasket
    {
        $partner = (new Partner())->setName('Socix')->setSurname('Prueba');
        if ($email !== null) {
            $partner->setEmail($email);
        }

        $share = $this->createMock(BasketShare::class);
        $share->method('getId')->willReturn($shareId);

        return (new WeeklyBasket())
            ->setPartner($partner)
            ->setWeeklyBasketGroup((new WeeklyBasketGroup())->setName($node->getName())->setNode($node))
            ->setBasketShare($share)
            ->setDeliveryDate($deliveryDate);
    }
}
