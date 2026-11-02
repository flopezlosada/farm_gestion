<?php

namespace App\Tests\Command;

use App\Entity\EmittedEffect;
use App\Entity\Notification;
use App\Entity\OfficialNotice;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Accounting\Watch\AccountingWatchAlert;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * La vigilancia de contabilidad con la portada real: lo urgente avisa a los 3 días del
 * último aviso aunque el reloj pase varias veces, contando el que dio el buzón al
 * guardar la notificación; vuelve a avisar mientras siga sin resolver y deja de
 * avisar en cuanto se resuelve. Sin nadie que lleve la contabilidad no avisa ni da
 * nada por avisado.
 */
class SendAccountingWatchCommandTest extends KernelTestCase
{
    private const TITLE = 'Notificación oficial sin revisar (DEHú)';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $this->em->persist((new User())
            ->setUsername('watch-test-tesorera')
            ->setEmail('watch-test-tesorera@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles(['ROLE_GESTION_CONTABILIDAD_EDIT']));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(EmittedEffect::class)->findBy(['kind' => [AccountingWatchAlert::KIND_URGENT, AccountingWatchAlert::KIND_WEEKLY]]) as $effect) {
            $this->em->remove($effect);
        }
        foreach ($this->em->getRepository(Notification::class)->findBy(['kind' => Notification::KIND_ACCOUNTING_AGENDA]) as $notice) {
            $this->em->remove($notice);
        }
        foreach ($this->em->getRepository(OfficialNotice::class)->findAll() as $notice) {
            $this->em->remove($notice);
        }
        foreach ($this->em->getRepository(User::class)->findBy(['username' => 'watch-test-tesorera']) as $user) {
            $this->em->remove($user);
        }
        foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
            $this->em->remove($setting);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testLoUrgenteSeRepiteATresDiasDelUltimoAvisoMientrasSiga(): void
    {
        $notice = $this->notice('d');

        $this->runCommand('2026-11-02');
        $this->runCommand('2026-11-03');
        $this->runCommand('2026-11-04');
        $this->assertSame(0, $this->urgentNotices(), 'El buzón ya avisó el día 2 al guardarla: nada hasta el 5.');

        $this->runCommand('2026-11-05');
        $this->runCommand('2026-11-06');
        $this->assertSame(1, $this->urgentNotices(), 'A los 3 días, un aviso; al día siguiente, no.');
        $this->assertSame(1, $this->urgentEmails(), 'Y un solo correo, a la tesorera.');

        $this->runCommand('2026-11-08');
        $this->assertSame(2, $this->urgentNotices(), 'Sigue sin mirarse: a los 3 días del último, otro.');

        $notice = $this->em->find(OfficialNotice::class, $notice->getId());
        $notice->markReviewed($this->treasurer(), new \DateTimeImmutable('2026-11-09 10:00'));
        $this->em->flush();

        $this->runCommand('2026-11-11');
        $this->assertSame(2, $this->urgentNotices(), 'Revisada: ya no sale en la portada y no avisa más.');
    }

    /** Dos notificaciones del mismo organismo tienen cada una su recordatorio. */
    public function testDosNotificacionesDelMismoOrganismoAvisanCadaUna(): void
    {
        $this->notice('g');
        $this->notice('h');

        $this->runCommand('2026-11-05');

        $this->assertSame(2, $this->urgentNotices());
    }

    public function testSinNadieQueLleveLaContabilidadFallaYNoDaNadaPorAvisado(): void
    {
        $treasurer = $this->treasurer();
        $treasurer->setRoles(['ROLE_GESTION_CONTABILIDAD']);
        $this->em->flush();
        $this->notice('f');

        [$exit, $display] = $this->runCommandWithExit('2026-11-05');

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Nadie lleva la contabilidad', $display);
        $this->assertSame(0, $this->emittedEffects(), 'Nada queda apuntado como avisado.');
        $this->assertSame(0, $this->urgentEmails());

        $treasurer = $this->treasurer();
        $treasurer->setRoles(['ROLE_GESTION_CONTABILIDAD_EDIT']);
        $this->em->flush();

        $this->runCommand('2026-11-05');
        $this->assertSame(1, $this->urgentNotices(), 'En cuanto alguien lleva la contabilidad, se le avisa ese mismo día.');
    }

    public function testElDryRunNoAvisa(): void
    {
        $this->notice('e');

        $display = $this->runCommand('2026-11-05', ['--dry-run' => true]);

        $this->assertStringContainsString(self::TITLE, $display);
        $this->assertSame(0, $this->urgentNotices());
    }

    /**
     * Una notificación de la DEHú como la deja el buzón: llegó el 2-nov a las 9:12 y se
     * guardó (y se avisó) a las 9:15. La fecha de guardado se fija a mano porque la
     * entidad la toma del reloj y la tarea corre con fechas fingidas.
     *
     * @param string $seed Letra para su huella, distinta en cada una.
     */
    private function notice(string $seed): OfficialNotice
    {
        $notice = new OfficialNotice(str_repeat($seed, 64), 'DEHú', 'noreply.dehu@correo.gob.es', new \DateTimeImmutable('2026-11-02 09:12'));
        $this->em->persist($notice);
        $this->em->flush();
        $this->em->getConnection()->update('official_notice', ['created_at' => '2026-11-02 09:15:00'], ['id' => $notice->getId()]);
        $this->em->refresh($notice);

        return $notice;
    }

    /**
     * Pasa la tarea como si hoy fuera ese día.
     *
     * @param array<string, mixed> $input Opciones de más.
     */
    private function runCommand(string $date, array $input = []): string
    {
        return $this->runCommandWithExit($date, $input)[1];
    }

    /**
     * Pasa la tarea como si hoy fuera ese día y devuelve también el código de salida.
     *
     * @param array<string, mixed> $input Opciones de más.
     *
     * @return array{int, string}
     */
    private function runCommandWithExit(string $date, array $input = []): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:send-accounting-watch'));
        $exit = $tester->execute($input + ['--force' => true, '--date' => $date]);

        return [$exit, preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    /** Los correos de la notificación que han salido hacia la tesorera. */
    private function urgentEmails(): int
    {
        return \count(array_filter(
            self::getMailerMessages(),
            static fn ($message): bool => $message instanceof Email
                && str_contains((string) $message->getSubject(), self::TITLE)
                && 'watch-test-tesorera@example.test' === $message->getTo()[0]->getAddress(),
        ));
    }

    /** Los avisos de la vigilancia apuntados como emitidos. */
    private function emittedEffects(): int
    {
        return \count($this->em->getRepository(EmittedEffect::class)->findBy(['kind' => [AccountingWatchAlert::KIND_URGENT, AccountingWatchAlert::KIND_WEEKLY]]));
    }

    /** Los avisos de la notificación que han llegado a la bandeja de la tesorera. */
    private function urgentNotices(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Notification::class)->findBy([
            'kind' => Notification::KIND_ACCOUNTING_AGENDA,
            'title' => self::TITLE,
            'recipient' => $this->treasurer(),
        ]));
    }

    /** La cuenta con permiso de escribir en contabilidad que crea el setUp. */
    private function treasurer(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => 'watch-test-tesorera']);
    }
}
