<?php

namespace App\Command;

use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBot;
use App\Service\Telegram\TelegramBotApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Atiende el bot preguntando a Telegram por los mensajes nuevos, en vez de esperar
 * a que Telegram llame al webhook.
 *
 * Es para LOCAL: Telegram no puede llamar a un DDEV. En el servidor manda el
 * webhook, y mientras haya uno puesto Telegram no deja usar esto (lo dice el error).
 * Telegram guarda los mensajes que nadie ha recogido unas 24 horas.
 */
#[AsCommand(name: 'app:telegram-poll', description: 'Atiende el bot de Telegram recogiendo los mensajes a mano (para local).')]
class TelegramPollCommand extends Command
{
    /** Segundos que se espera en cada pregunta si no llega nada. */
    private const WAIT = 25;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramBotApi $api,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Atiende lo que haya y termina, sin quedarse esperando');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->bot->isEnabled()) {
            $io->error('No hay bot: falta TELEGRAM_BOT_TOKEN en .env.local.');

            return Command::FAILURE;
        }

        $once = (bool) $input->getOption('once');
        $offset = 0;
        $io->note($once ? 'Atendiendo lo pendiente…' : 'Atendiendo el bot. Ctrl+C para parar.');

        do {
            try {
                $updates = $this->api->getUpdates($offset, $once ? 0 : self::WAIT);
            } catch (TelegramApiException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            foreach ($updates as $update) {
                // Pedir a partir del siguiente es lo que le dice a Telegram que éste
                // ya está atendido: no vuelve a darlo.
                $offset = (int) $update['update_id'] + 1;
                $io->writeln(sprintf('Mensaje %d', $update['update_id']));
                $this->bot->handle($update);
            }
        } while (!$once || $updates !== []);

        if ($offset > 0) {
            // Confirma lo último atendido, para que la próxima vez no se repita.
            try {
                $this->api->getUpdates($offset, 0);
            } catch (TelegramApiException $e) {
                $io->warning('Atendido, pero no se ha podido confirmar a Telegram: la próxima vez puede repetirse. ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
