<?php

namespace App\EventListener;

use App\Controller\TelegramWebhookController;
use App\Service\Telegram\TelegramBot;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Atiende el mensaje que ha llegado por el webhook DESPUÉS de haber contestado a
 * Telegram (bajo php-fpm, tras `fastcgi_finish_request()`). Así Telegram no espera
 * a que se descargue y se lea la factura, ni la reintenta por impaciente.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
class TelegramUpdateListener
{
    public function __construct(private readonly TelegramBot $bot)
    {
    }

    /**
     * @param TerminateEvent $event Evento de fin de petición.
     */
    public function __invoke(TerminateEvent $event): void
    {
        $update = $event->getRequest()->attributes->get(TelegramWebhookController::UPDATE_ATTRIBUTE);
        if (!\is_array($update)) {
            return;
        }

        @set_time_limit(0);

        $this->bot->handle($update);
    }
}
