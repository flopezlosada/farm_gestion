<?php

namespace App\Tests\Command;

use App\Entity\CashCount;
use App\Entity\EmittedEffect;
use App\Entity\FinancialAccount;
use App\Entity\TelegramLink;
use App\Entity\User;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El recordatorio del arqueo con dos personas que pueden mandarlo: va a las dos una
 * vez al mes, y si Telegram rechaza a una (bot bloqueado) la otra lo recibe igual y a
 * la rechazada se le vuelve a intentar en la pasada siguiente.
 */
class SendCashCountReminderCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private AppSettings $settings;

    private bool $accountingWasOn;

    /** Sufijo para no chocar con nada que haya en la base de tests. */
    private string $tag;

    private FinancialAccount $garden;

    /** @var array<string, string> Telegram id de cada persona, por nombre de usuario. */
    private array $telegramIds = [];

    /** @var list<string> Chats a los que se ha escrito, en orden. */
    private array $sentTo = [];

    /** @var list<string> Chats que Telegram rechaza. */
    private array $blocked = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(AppSettings::class);
        $this->accountingWasOn = $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->tag = 't' . bin2hex(random_bytes(3));

        $this->garden = (new FinancialAccount())->setName('Caja huerta ' . $this->tag)->setKind(FinancialAccount::KIND_CASH)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2020-01-01'))->setActive(true)->setSortOrder(99);
        $this->em->persist($this->garden);
        foreach (['ana', 'bea'] as $i => $name) {
            $user = (new User())
                ->setUsername($name . '-' . $this->tag)
                ->setEmail($name . '-' . $this->tag . '@test.org')
                ->setPassword('x')
                ->setEnabled(true)
                ->setPasswordSet(true)
                ->setRoles(['ROLE_BOT_CASH_COUNT']);
            $this->em->persist($user);
            $this->em->flush();
            $telegramId = (string) (880000 + $i);
            $link = new TelegramLink($user);
            $link->link($telegramId, $name, new \DateTimeImmutable());
            $this->em->persist($link);
            $this->telegramIds[$user->getUsername()] = $telegramId;
        }
        $this->em->flush();

        $bot = $this->createMock(TelegramBotApi::class);
        $bot->method('isEnabled')->willReturn(true);
        $bot->method('sendMessage')->willReturnCallback(function (string $chatId): int {
            if (\in_array($chatId, $this->blocked, true)) {
                throw new TelegramApiException('Forbidden: bot was blocked by the user');
            }
            $this->sentTo[] = $chatId;

            return 1;
        });
        self::getContainer()->set(TelegramBotApi::class, $bot);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->em->getRepository(EmittedEffect::class)->findBy(['kind' => 'cash_count_reminder']) as $effect) {
            $this->em->remove($effect);
        }
        foreach ($this->em->getRepository(CashCount::class)->findBy(['account' => $this->garden->getId()]) as $count) {
            $this->em->remove($count);
        }
        foreach (array_keys($this->telegramIds) as $username) {
            $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
            foreach ($this->em->getRepository(TelegramLink::class)->findBy(['user' => $user]) as $link) {
                $this->em->remove($link);
            }
            $this->em->remove($user);
        }
        $this->em->remove($this->em->find(FinancialAccount::class, $this->garden->getId()));
        $this->em->flush();
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, $this->accountingWasOn);

        parent::tearDown();
    }

    public function testPideElArqueoUnaVezAlMesACadaPersona(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand('2026-11-01'));
        $this->assertEqualsCanonicalizing(array_values($this->telegramIds), $this->sentTo);

        $this->runCommand('2026-11-02');
        $this->assertCount(2, $this->sentTo, 'El mismo mes no se repite.');
    }

    public function testUnChatQueRechazaNoDejaSinAvisoALosDemasYSeReintenta(): void
    {
        $first = array_key_first($this->telegramIds);
        $this->blocked = [$this->telegramIds[$first]];

        $this->assertSame(Command::SUCCESS, $this->runCommand('2026-11-01'), 'A una le ha llegado: la tarea ha hecho su trabajo.');
        $this->assertSame([$this->telegramIds[array_key_last($this->telegramIds)]], $this->sentTo);

        $this->blocked = [];
        $this->runCommand('2026-11-02');
        $this->assertSame($this->telegramIds[$first], end($this->sentTo), 'A la que falló se le pide en la pasada siguiente.');
        $this->assertCount(2, $this->sentTo, 'Y a la que ya lo tenía no se le repite.');
    }

    public function testSiTelegramRechazaATodasLaTareaFalla(): void
    {
        $this->blocked = array_values($this->telegramIds);

        $this->assertSame(Command::FAILURE, $this->runCommand('2026-11-01'));
        $this->assertSame([], $this->sentTo);
    }

    /** Pasa la tarea como si hoy fuera ese día. */
    private function runCommand(string $date): int
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:send-cash-count-reminder'));

        return $tester->execute(['--force' => true, '--date' => $date]);
    }
}
