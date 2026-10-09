<?php

namespace App\Controller;

use App\Service\Telegram\TelegramBotApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Por donde Telegram entrega en el servidor los mensajes que recibe el bot.
 *
 * Mismas defensas que {@see CronTickController}: sin bot configurado no existe
 * (404), el secreto viaja en cabecera, se compara con `hash_equals` y quien no lo
 * traiga recibe un 404 opaco. El secreto se lo dio la aplicación a Telegram al
 * registrar el webhook ({@see TelegramBotApi::setWebhook()}).
 *
 * EL TRABAJO NO SE HACE AQUÍ. Se contesta 200 al instante y el mensaje se atiende
 * en `kernel.terminate` ({@see \App\EventListener\TelegramUpdateListener}): leer una
 * factura tarda segundos, y Telegram reintenta lo que no contesta a tiempo, así que
 * esperar aquí sólo traería mensajes duplicados.
 */
class TelegramWebhookController
{
    /** Cabecera en la que Telegram manda el secreto. */
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /** Atributo con el que el controlador le pasa el mensaje al listener. */
    public const UPDATE_ATTRIBUTE = '_telegram_update';

    /**
     * @param TelegramBotApi $api API del bot, por su secreto.
     */
    public function __construct(private readonly TelegramBotApi $api)
    {
    }

    /**
     * @param Request $request Llamada de Telegram.
     */
    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $given = (string) $request->headers->get(self::SECRET_HEADER, '');
        if (!$this->api->isEnabled() || !hash_equals($this->api->webhookSecret(), $given)) {
            throw new NotFoundHttpException();
        }

        $update = json_decode($request->getContent(), true);
        if (\is_array($update)) {
            $request->attributes->set(self::UPDATE_ATTRIBUTE, $update);
        }

        return new Response('', Response::HTTP_OK);
    }
}
