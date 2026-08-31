<?php

namespace App\Tests\Command;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\EmittedEffect;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El recordatorio de "te toca voluntariado".
 *
 * Lo que se protege es lo que este comando cambió al llegar la bandeja: la copia
 * in-app se escribe SIEMPRE, y el empujón al móvil es lo único que respeta la
 * preferencia del socix. Antes, quien tenía el móvil apagado no recibía nada de
 * aquí y se confiaba en que entrase a mirar su panel — que es justo lo que este
 * recordatorio existe para no tener que suponer.
 *
 * Los datos son propios y con títulos marcados "TEST", y se limpia todo lo que
 * escribe —incluido el apunte del ledger—: la bandeja es acumulativa, y un apunte
 * olvidado dejaría el recordatorio por emitido y haría pasar al test siguiente por
 * el motivo equivocado.
 *
 * Las tareas empiezan "en una hora" y no en una fecha fija: el finder cruza la
 * ventana de antelación configurada ({@see AppSettings::VOLUNTEERING_REMINDER_HOURS}),
 * así que una fecha lejana quedaría fuera y el test pasaría sin comprobar nada.
 */
class SendVolunteerRemindersCommandTest extends KernelTestCase
{
    /** Clase de efecto con la que el comando apunta cada recordatorio. */
    private const EFFECT_KIND = 'volunteer_reminder';

    /**
     * Deja los ajustes como estaban.
     *
     * El módulo de voluntariado está apagado por defecto y hay que encenderlo para
     * que el cron no se inhibe entero (`requires` en AppSettings::CRON_TASKS, que
     * ni --force salta). Sin devolverlo a su sitio, los tests que cuentan con el
     * default lo encontrarían encendido según el orden de ejecución.
     */
    protected function tearDown(): void
    {
        if (static::$booted) {
            $em = $this->em();
            foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
                $em->remove($setting);
            }
            $em->flush();
        }

        parent::tearDown();
    }

    /**
     * Con una inscripción en ventana, el recordatorio deja su copia en la bandeja
     * de quien se apuntó, con el título y el cuerpo del aviso.
     */
    public function testDejaLaCopiaEnLaBandejaDeQuienSeApunto(): void
    {
        self::bootKernel();
        // El módulo tiene que estar encendido o el cron se inhibe entero.
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);
        $em = $this->em();

        $socix = $em->getRepository(User::class)->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);
        self::assertNotNull($socix, 'Fixtures sin User socix; carga PartnerUserFixtures en db_test.');
        $partner = $socix->getPartner();
        self::assertNotNull($partner, 'El User socix de las fixtures debería tener Partner.');

        // La tarea empieza en una hora: dentro de cualquier ventana razonable de
        // antelación, así que el finder la coge sea cual sea el ajuste.
        $offer = (new VolunteerOffer())
            ->setTitle('TEST Descargar el reparto')
            ->setStartsAt(new \DateTime('+1 hour'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(3);
        $em->persist($offer);

        $signup = (new VolunteerSignup())->setOffer($offer)->setPartner($partner);
        $em->persist($signup);
        $em->flush();

        $offerId = $offer->getId();
        $signupId = $signup->getId();

        $tester = $this->commandTester();
        $exit = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $em = $this->em();
        $avisos = $em->getRepository(Notification::class)->findBy([
            'recipient' => $socix,
            'kind' => Notification::KIND_VOLUNTEERING_REMINDER,
        ]);

        $delEsteTest = array_values(array_filter(
            $avisos,
            static fn (Notification $n): bool => str_contains((string) $n->getBody(), 'TEST Descargar el reparto')
        ));

        self::assertCount(1, $delEsteTest, 'Debería haber una copia, y sólo una, del recordatorio.');
        self::assertSame('Te toca voluntariado', $delEsteTest[0]->getTitle());
        self::assertNull($delEsteTest[0]->getReadAt(), 'Un aviso recién dejado no puede nacer leído.');

        $this->cleanUp($signupId, $offerId, array_map(static fn (Notification $n): int => (int) $n->getId(), $delEsteTest));
    }

    /**
     * Segunda pasada del reloj (corre cada hora sobre la misma ventana): el apunte
     * del ledger ya existe y no se escribe una segunda copia. Sin esto, a quien se
     * apuntó le quedarían veinticuatro filas idénticas en la bandeja.
     */
    public function testNoRepiteLaCopiaEnLaSegundaPasada(): void
    {
        self::bootKernel();
        // El módulo tiene que estar encendido o el cron se inhibe entero.
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);
        $em = $this->em();

        $socix = $em->getRepository(User::class)->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);
        self::assertNotNull($socix);
        $partner = $socix->getPartner();
        self::assertNotNull($partner);

        $offer = (new VolunteerOffer())
            ->setTitle('TEST Sólo una vez')
            ->setStartsAt(new \DateTime('+1 hour'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setSlots(2);
        $em->persist($offer);

        $signup = (new VolunteerSignup())->setOffer($offer)->setPartner($partner);
        $em->persist($signup);
        $em->flush();

        $offerId = $offer->getId();
        $signupId = $signup->getId();

        $this->commandTester()->execute(['--force' => true]);
        $this->commandTester()->execute(['--force' => true]);

        $em = $this->em();
        $avisos = array_values(array_filter(
            $em->getRepository(Notification::class)->findBy([
                'recipient' => $socix,
                'kind' => Notification::KIND_VOLUNTEERING_REMINDER,
            ]),
            static fn (Notification $n): bool => str_contains((string) $n->getBody(), 'TEST Sólo una vez')
        ));

        self::assertCount(1, $avisos, 'Dos pasadas del reloj, una sola copia.');

        $this->cleanUp($signupId, $offerId, array_map(static fn (Notification $n): int => (int) $n->getId(), $avisos));
    }

    /**
     * Borra lo que el test ha escrito: la inscripción, la tarea, las copias de la
     * bandeja y el apunte del ledger.
     *
     * El apunte importa tanto como el resto: sin borrarlo, una ejecución posterior
     * del comando encontraría el recordatorio por emitido y el test siguiente
     * pasaría por el motivo equivocado.
     *
     * @param int       $signupId       id de la inscripción
     * @param int       $offerId        id de la tarea
     * @param list<int> $notificationIds ids de las copias escritas
     */
    private function cleanUp(int $signupId, int $offerId, array $notificationIds): void
    {
        $em = $this->em();

        foreach ($notificationIds as $id) {
            $aviso = $em->find(Notification::class, $id);
            if (null !== $aviso) {
                $em->remove($aviso);
            }
        }

        $signup = $em->find(VolunteerSignup::class, $signupId);
        if (null !== $signup) {
            $em->remove($signup);
        }

        $em->flush();

        $offer = $em->find(VolunteerOffer::class, $offerId);
        if (null !== $offer) {
            $em->remove($offer);
        }

        foreach ($em->getRepository(EmittedEffect::class)->findBy([
            'kind' => self::EFFECT_KIND,
            'reference' => 'signup-' . $signupId,
        ]) as $apunte) {
            $em->remove($apunte);
        }

        $em->flush();
    }

    private function commandTester(): CommandTester
    {
        $command = (new Application(static::$kernel))->find('app:send-volunteer-reminders');

        return new CommandTester($command);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
