<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Repository\UserRepository;
use App\Repository\VolunteerOfferRepository;
use App\Service\AppSettings;
use App\Service\Push\PushSender;
use App\Security\PartnerAccessPolicy;
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
 * Lo que se fija aquí son las tres formas de NO mandar: con el módulo apagado,
 * sin nadie a quien avisar, y cuando otro proceso se ha adelantado. Las tres
 * acaban en un aviso no enviado, que es siempre el error barato; el caro es el
 * aviso repetido.
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
     * Socixs que todavía no tienen cuenta de acceso tampoco cuentan: un push
     * necesita una sesión detrás. Se avisa a quien se puede y no se registra
     * nada si no se puede a nadie.
     */
    public function testSocixsSinCuentaNoGeneranAviso(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturn([]);

        $notifier = $this->notifier(
            audience: $this->audienceReturning([new Partner()]),
            users: $users,
            entityManager: $entityManager
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

        $notifier = $this->notifier(
            audience: $this->audienceReturning([new Partner(), new Partner()]),
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

    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(3);
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
    ): VolunteerCallNotifier {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($enabled);

        $defaultUsers = $this->createMock(UserRepository::class);
        $defaultUsers->method('findByPartners')->willReturn([new User()]);

        // Todo el mundo quiere el aviso: estos casos comprueban a quién se le
        // manda y cuándo, no la política de preferencias, que tiene los suyos.
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('wants')->willReturn(true);
        // filter() es el que usan de verdad estos servicios —una consulta para
        // toda la lista en vez de una por socix—: devuelve a todo el mundo.
        $preferences->method('filter')->willReturnArgument(0);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/panel/voluntariado');

        return new VolunteerCallNotifier(
            $offers ?? $this->createMock(VolunteerOfferRepository::class),
            $users ?? $defaultUsers,
            $audience ?? $this->audienceReturning([]),
            $this->createMock(VolunteerCallEscalator::class),
            $push ?? $this->createMock(PushSender::class),
            $preferences,
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
