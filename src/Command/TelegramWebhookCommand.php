<?php

namespace App\Command;

use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Registra, quita o enseña el webhook del bot: la dirección a la que Telegram
 * entrega los mensajes en el servidor.
 *
 * Se lanza UNA vez por servidor (y otra si cambia el token o el dominio). La
 * dirección sale de DEFAULT_URI, así que en cada entorno apunta a sí mismo.
 */
#[AsCommand(name: 'app:telegram-webhook', description: 'Registra (set), quita (delete) o enseña (info) el webhook del bot de Telegram.')]
class TelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'set, delete o info', 'info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            switch ($input->getArgument('action')) {
                case 'set':
                    $url = $this->urls->generate('telegram_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL);
                    if (!str_starts_with($url, 'https://')) {
                        $io->error(sprintf('Telegram sólo llama a direcciones https, y ésta es %s. Revisa DEFAULT_URI.', $url));

                        return Command::FAILURE;
                    }
                    $this->api->setWebhook($url);
                    $io->success('Webhook registrado: ' . $url);
                    break;
                case 'delete':
                    $this->api->deleteWebhook();
                    $io->success('Webhook quitado: los mensajes esperan a app:telegram-poll.');
                    break;
                case 'info':
                    $info = $this->api->getWebhookInfo();
                    $io->definitionList(
                        ['Dirección' => ($info['url'] ?? '') !== '' ? $info['url'] : '(ninguna)'],
                        ['Mensajes en cola' => (string) ($info['pending_update_count'] ?? 0)],
                        ['Último error' => isset($info['last_error_date'])
                            ? date('d/m/Y H:i', (int) $info['last_error_date']) . ' · ' . ($info['last_error_message'] ?? '')
                            : '(ninguno)'],
                    );
                    break;
                default:
                    $io->error('La acción es set, delete o info.');

                    return Command::INVALID;
            }
        } catch (TelegramApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
