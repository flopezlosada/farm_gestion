<?php

namespace App\Command;

use App\Entity\FinancialAccount;
use App\Repository\CashCountRepository;
use App\Repository\TelegramLinkRepository;
use App\Service\Accounting\CashCount\CashCountDue;
use App\Service\Accounting\CashCount\CashCountRecorder;
use App\Service\Notification\StaffAudience;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pide el arqueo de fin de mes por el bot de Telegram a quien lo hace: las cajas sin
 * contar desde la última semana del mes anterior ({@see CashCountDue}). Una vez al
 * mes por persona; si ya está todo contado, no escribe.
 *
 * Va a quien tiene el permiso de arqueo como responsable (no al admin por jerarquía)
 * y tiene el bot vinculado: es quien puede contestar con el recuento en ese mismo
 * chat. En un chat privado, el id del chat es el de la persona en Telegram.
 */
#[AsCommand(name: 'app:send-cash-count-reminder', description: 'Pide por el bot el arqueo de las cajas sin contar este mes.')]
class SendCashCountReminderCommand extends AbstractCronCommand
{
    /** Permiso de mandar el arqueo al bot. */
    public const ROLE = 'ROLE_BOT_CASH_COUNT';

    /** Clase de efecto: un recordatorio por persona y mes. */
    private const EFFECT_KIND = 'cash_count_reminder';

    public function __construct(
        private readonly CashCountRecorder $recorder,
        private readonly CashCountRepository $counts,
        private readonly StaffAudience $staff,
        private readonly TelegramLinkRepository $links,
        private readonly TelegramBotApi $bot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Hacer como si hoy fuera esta fecha (YYYY-MM-DD)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el recordatorio aunque ya conste este mes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice a quién lo pediría y qué cajas, sin escribir');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $date = $this->optionalDate($input, $io);
        if (false === $date) {
            return Command::INVALID;
        }
        $today = $date ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));

        if (!$this->bot->isEnabled()) {
            $io->warning('No hay bot de Telegram configurado: no se puede pedir el arqueo.');

            return $this->nothingToDo('Sin bot de Telegram');
        }
        $pending = CashCountDue::pending($this->recorder->openCashAccounts(), $this->counts->findLatestOfActiveCashAccounts(), $today);
        if ($pending === []) {
            $io->success('Todas las cajas están contadas este mes.');

            return $this->nothingToDo('Cajas contadas');
        }
        $linked = [];
        foreach ($this->links->findAllWithUser() as $link) {
            if ($link->isLinked()) {
                $linked[(int) $link->getUser()->getId()] = (string) $link->getTelegramUserId();
            }
        }
        $chats = [];
        foreach ($this->staff->responsiblesFor(self::ROLE) as $user) {
            if (isset($linked[(int) $user->getId()])) {
                $chats[(string) $user->getUsername()] = $linked[(int) $user->getId()];
            }
        }
        if ($chats === []) {
            $io->warning(sprintf('Nadie con el permiso %s tiene el bot vinculado: no hay a quién pedírselo.', self::ROLE));

            return $this->nothingToDo('Nadie a quien pedir el arqueo');
        }

        $text = self::message($pending);
        $io->writeln($text);
        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: se pediría a %s.', implode(', ', array_keys($chats))));

            return Command::SUCCESS;
        }

        $month = $today->modify('first day of this month');
        $sent = 0;
        $failed = [];
        foreach ($chats as $username => $chatId) {
            // Un chat que rechaza el mensaje (bot bloqueado, chat borrado) no puede dejar
            // sin aviso a quien va detrás: el orden es siempre el mismo, así que cortar
            // aquí lo cortaría todos los días. El ledger ya ha soltado su apunte, y
            // mañana se le vuelve a intentar.
            try {
                if ($this->emitOnce(self::EFFECT_KIND, fn () => $this->bot->sendMessage($chatId, $text), $input, 'user-' . $username, $month, 'telegram')) {
                    ++$sent;
                }
            } catch (TelegramApiException $e) {
                $failed[] = $username;
                $io->warning(sprintf('Telegram no ha dejado escribir a %s: %s', $username, $e->getMessage()));
            }
        }
        if ($failed !== [] && $sent === 0) {
            $io->error(sprintf('No se ha podido pedir el arqueo a nadie (falló: %s).', implode(', ', $failed)));

            return Command::FAILURE;
        }
        if ($sent === 0) {
            return $this->nothingToDo('Ya pedido este mes');
        }
        $detail = sprintf('Arqueo pedido a %d persona(s)', $sent) . ($failed !== [] ? sprintf('; falló: %s', implode(', ', $failed)) : '');
        $io->success($detail . '.');

        return $this->didWork($detail);
    }

    /**
     * Lo que dice el bot: qué cajas contar y cómo contestar.
     *
     * @param list<FinancialAccount> $pending Las cajas sin contar; al menos una.
     */
    public static function message(array $pending): string
    {
        $names = array_map(static fn (FinancialAccount $a): string => $a->getName(), $pending);
        $last = array_pop($names);
        $list = $names === [] ? $last : implode(', ', $names) . ' y ' . $last;

        return sprintf(
            'Toca el arqueo de fin de mes: cuenta el dinero de %s y mándamelo aquí, por ejemplo «%s 450,50» (vale también una nota de voz). Te diré lo que he entendido antes de guardarlo.',
            $list,
            mb_strtolower($pending[0]->getName()),
        );
    }
}
