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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La vigilancia de contabilidad con la portada real: lo urgente avisa una vez por
 * tramo de 3 días aunque el reloj pase varias veces, vuelve a avisar en el tramo
 * siguiente si sigue sin resolver, y deja de avisar en cuanto se resuelve.
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

    public function testLoUrgenteAvisaUnaVezPorTramoYSeRepiteMientrasSiga(): void
    {
        $notice = new OfficialNotice(str_repeat('d', 64), 'DEHú', 'noreply.dehu@correo.gob.es', new \DateTimeImmutable('2026-11-02 09:12'));
        $this->em->persist($notice);
        $this->em->flush();

        $this->runCommand('2026-11-03');
        $this->runCommand('2026-11-04');
        $this->assertSame(1, $this->urgentNotices(), 'Dentro del mismo tramo de 3 días, un solo aviso.');

        $this->runCommand('2026-11-06');
        $this->assertSame(2, $this->urgentNotices(), 'Sigue sin mirarse: el tramo siguiente vuelve a avisar.');

        $notice = $this->em->find(OfficialNotice::class, $notice->getId());
        $notice->markReviewed($this->treasurer(), new \DateTimeImmutable('2026-11-07 10:00'));
        $this->em->flush();

        $this->runCommand('2026-11-09');
        $this->assertSame(2, $this->urgentNotices(), 'Revisada: ya no sale en la portada y no avisa más.');
    }

    public function testElDryRunNoAvisa(): void
    {
        $this->em->persist(new OfficialNotice(str_repeat('e', 64), 'DEHú', 'noreply.dehu@correo.gob.es', new \DateTimeImmutable('2026-11-02 09:12')));
        $this->em->flush();

        $display = $this->runCommand('2026-11-03', ['--dry-run' => true]);

        $this->assertStringContainsString(self::TITLE, $display);
        $this->assertSame(0, $this->urgentNotices());
    }

    /**
     * Pasa la tarea como si hoy fuera ese día.
     *
     * @param array<string, mixed> $input Opciones de más.
     */
    private function runCommand(string $date, array $input = []): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:send-accounting-watch'));
        $tester->execute($input + ['--force' => true, '--date' => $date]);

        return preg_replace('/\s+/', ' ', $tester->getDisplay());
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
