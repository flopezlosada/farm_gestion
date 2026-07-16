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
use App\Service\Delivery\PickupReminderMailer;
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

        $this->assertSame(['sent' => 1, 'skipped' => 1], $result);
        $this->assertSame('ok@test.org', $captured[0]->getTo()[0]->getAddress());
        $this->assertSame('email/pickup_reminder.html.twig', $captured[0]->getHtmlTemplate());
        $this->assertSame('email/pickup_reminder.txt.twig', $captured[0]->getTextTemplate());
        $this->assertStringNotContainsStringIgnoringCase('viernes', $captured[0]->getSubject(), 'El asunto ya no fija "viernes".');
    }

    private function mailer(?MailerInterface $mailerMock = null): PickupReminderMailer
    {
        // Links apagados: canUseActionLinks no se llega a invocar (corto-circuito).
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn(false);

        $deadline = $this->createMock(DeliveryDeadline::class);
        $deadline->method('fromPhysicalDate')->willReturn(new \DateTimeImmutable('2099-06-30 20:59'));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://csavegadejarama.org/panel/calendario');

        return new PickupReminderMailer(
            $mailerMock ?? $this->createMock(MailerInterface::class),
            $settings,
            $this->createMock(PartnerAccessPolicy::class),
            $urlGenerator,
            $deadline,
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
