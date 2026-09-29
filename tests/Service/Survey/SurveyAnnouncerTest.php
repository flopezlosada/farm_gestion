<?php

namespace App\Tests\Service\Survey;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Survey;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\AppSettings;
use App\Service\Cron\EffectLedger;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use App\Service\Survey\SurveyAnnouncer;
use App\Service\Survey\SurveyLink;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del aviso de encuesta abierta.
 *
 * Lo propio de este aviso frente al del grupo de consumo es el ENLACE
 * PERSONAL: cada correo tiene que llevar el de su destinataria, porque con él
 * se responde en su nombre sin contraseña. Un enlace cruzado haría que una
 * socia respondiera como otra.
 */
class SurveyAnnouncerTest extends TestCase
{
    private Survey $survey;

    /** @var list<Partner> */
    private array $active = [];

    protected function setUp(): void
    {
        $this->survey = (new Survey())
            ->setTitle('Satisfacción de la temporada')
            ->setStatus(Survey::STATUS_OPEN)
            ->setClosesAt(new \DateTime('+10 days'));
        $this->setId($this->survey, Survey::class, 7);

        $this->active = [
            $this->partner(1, 'una@example.com'),
            $this->partner(2, 'otra@example.com'),
        ];
    }

    public function testAvisaPorLasTresViasYDejaConstanciaEnLaEncuesta(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send');

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects(self::once())->method('deliver')
            ->with(self::anything(), Notification::KIND_SURVEY_OPEN)
            ->willReturn(2);

        $result = $this->announcer(mailer: $mailer, push: $push, inbox: $inbox)->announce($this->survey);

        self::assertSame(['inbox' => 2, 'email' => 2, 'push' => 2], $result);
        self::assertNotNull($this->survey->getAnnouncedAt(), 'La encuesta tiene que quedar marcada como avisada.');
    }

    public function testCadaCorreoLlevaElEnlaceDeSuDestinataria(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (TemplatedEmail $email) use (&$sent): void {
            $sent[$email->getTo()[0]->getAddress()] = $email->getContext()['url'];
        });

        $this->announcer(mailer: $mailer)->announce($this->survey);

        self::assertSame([
            'una@example.com' => 'https://csa.example/surveys/7/respond/1?_hash=x',
            'otra@example.com' => 'https://csa.example/surveys/7/respond/2?_hash=x',
        ], $sent);
    }

    public function testConElCorreoApagadoSiguenSaliendoLaBandejaYElMovil(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $result = $this->announcer(mailer: $mailer, push: $push, emailEnabled: false)->announce($this->survey);

        self::assertSame(0, $result['email']);
        self::assertSame(2, $result['push']);
    }

    public function testNoSeEscribeAQuienHaSilenciadoElTema(): void
    {
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('filter')->willReturnCallback(
            fn (array $partners, string $topic, string $channel): array => NotificationTopic::SURVEYS === $topic && NotificationTopic::CHANNEL_EMAIL === $channel ? [] : $partners
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->announcer(mailer: $mailer, preferences: $preferences)->announce($this->survey);

        self::assertSame(0, $result['email']);
    }

    public function testUnCorreoQueFallaNoAbortaElResto(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $calls = 0;
        $mailer->expects(self::exactly(2))->method('send')->willReturnCallback(function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new TransportException('SMTP caído');
            }
        });

        $result = $this->announcer(mailer: $mailer)->announce($this->survey);

        self::assertSame(1, $result['email'], 'El segundo correo tiene que salir aunque el primero falle.');
    }

    /**
     * Reintentar el aviso no repite a nadie: el registro de efectos dice que ya
     * se emitió y no se ejecuta.
     */
    public function testLoQueYaConstaEnviadoNoSeRepite(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturn(false);

        $result = $this->announcer(mailer: $mailer, ledger: $ledger)->announce($this->survey);

        self::assertSame(['inbox' => 0, 'email' => 0, 'push' => 0], $result);
    }

    /**
     * La fecha de negocio del apunte es la de CREACIÓN de la encuesta: con la de
     * hoy, reintentar al día siguiente repetiría el aviso entero.
     */
    public function testElApunteSeFechaConLaCreacionDeLaEncuesta(): void
    {
        $seen = [];
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturnCallback(
            function (string $kind, string $reference, \DateTimeInterface $occurredOn) use (&$seen): bool {
                $seen[] = $occurredOn->format('Y-m-d');

                return true;
            }
        );

        $this->announcer(ledger: $ledger)->announce($this->survey);

        self::assertSame([$this->survey->getCreatedAt()->format('Y-m-d')], array_values(array_unique($seen)));
    }

    /**
     * Una socia de prueba con id y correo.
     */
    private function partner(int $id, string $email): Partner
    {
        $partner = (new Partner())->setEmail($email)->setName('Socia');
        $this->setId($partner, Partner::class, $id);

        return $partner;
    }

    /**
     * Fija el id, que en producción pone Doctrine al persistir.
     */
    private function setId(object $entity, string $class, int $id): void
    {
        $property = new \ReflectionProperty($class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    /**
     * El announcer con todo mockeado y valores por defecto razonables: correo
     * encendido, nadie silenciado y un ledger que deja pasar todos los efectos.
     */
    private function announcer(
        ?MailerInterface $mailer = null,
        ?PushSender $push = null,
        ?NotificationInbox $inbox = null,
        ?NotificationPreferences $preferences = null,
        ?EffectLedger $ledger = null,
        bool $emailEnabled = true,
    ): SurveyAnnouncer {
        $partners = $this->createMock(PartnerRepository::class);
        $partners->method('findActive')->willReturn($this->active);
        $partners->method('findActiveWithEmail')->willReturn($this->active);

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturnCallback(
            static fn (array $list): array => array_map(static fn (): User => new User(), $list)
        );

        if (null === $preferences) {
            $preferences = $this->createMock(NotificationPreferences::class);
            $preferences->method('filter')->willReturnCallback(static fn (array $list): array => $list);
        }

        if (null === $ledger) {
            $ledger = $this->createMock(EffectLedger::class);
            $ledger->method('once')->willReturnCallback(
                static function (string $kind, string $reference, \DateTimeInterface $on, callable $effect): bool {
                    $effect();

                    return true;
                }
            );
        }

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($emailEnabled);

        $links = $this->createMock(SurveyLink::class);
        $links->method('forPartner')->willReturnCallback(
            static fn (Survey $survey, Partner $partner): string => sprintf('https://csa.example/surveys/%d/respond/%d?_hash=x', $survey->getId(), $partner->getId())
        );

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://csa.example/panel/avisos');

        return new SurveyAnnouncer(
            $partners,
            $users,
            $inbox ?? $this->createMock(NotificationInbox::class),
            $this->createMock(NotificationLink::class),
            $preferences,
            $push ?? $this->createMock(PushSender::class),
            $this->createMock(PushSubscriptionRepository::class),
            $ledger,
            $this->createMock(EntityManagerInterface::class),
            $mailer ?? $this->createMock(MailerInterface::class),
            $settings,
            $links,
            $urls,
            $this->createMock(LoggerInterface::class),
        );
    }
}
