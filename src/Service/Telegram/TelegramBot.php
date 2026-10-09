<?php

namespace App\Service\Telegram;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Service\Ai\GeminiException;
use App\Service\Telegram\Destination\TelegramDestination;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * La puerta del bot: a cada mensaje le aplica lo común (sólo chats privados,
 * vincular con una invitación, no atender a quien no está vinculado) y lo manda a
 * su destino ({@see TelegramDestination}: facturas, y lo que venga).
 *
 * CÓMO SE ELIGE DESTINO:
 * - Se quedan los que aceptan esa clase de mensaje y a esa persona.
 * - Si queda uno, va ahí sin más: no se gasta una consulta a Gemini en una
 *   pregunta con una sola respuesta.
 * - Si quedan varios, decide {@see TelegramClassifier} mirando el contenido.
 * - Si no queda ninguno, o el contenido no encaja en ninguno, se dice y no se
 *   guarda nada.
 *
 * Llegue el mensaje por el webhook (servidor) o por getUpdates (local), pasa por
 * aquí. Añadir una opción al bot es añadir un destino, sin tocar esta clase.
 *
 * NUNCA atiende a quien no está vinculado, ni descarga lo que mande: a un bot de
 * Telegram le puede escribir cualquiera.
 *
 * Y lo que llega se atiende UNA vez: Telegram repite la entrega si no recibe
 * respuesta a tiempo, así que cada mensaje se marca por su `update_id` (que la
 * propia documentación de Telegram recomienda para eso). Un fallo al atender no
 * se propaga: se apunta y se le dice a quien escribió que lo vuelva a mandar.
 */
class TelegramBot
{
    /** Cuánto se recuerda un mensaje atendido. Telegram guarda los pendientes 24 h. */
    private const SEEN_SECONDS = 86400;

    private const STRANGER = 'Este bot es de la CSA Vega de Jarama y sólo atiende a su gente. Para usarlo, pide tu enlace a quien lleva la web de la asociación.';

    /**
     * @param TelegramBotApi                $api          API del bot.
     * @param TelegramLinkRepository        $links        Quién está vinculado.
     * @param iterable<TelegramDestination> $destinations Lo que sabe hacer.
     * @param TelegramClassifier            $classifier   Para elegir cuando hay varios.
     * @param EntityManagerInterface        $em           Para guardar el vínculo.
     * @param CacheItemPoolInterface        $seen         Mensajes ya atendidos.
     * @param RateLimiterFactory            $limiter      Cuántos mensajes por cuenta y hora.
     * @param LoggerInterface               $logger       Rastro de lo que falla.
     */
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly TelegramLinkRepository $links,
        #[AutowireIterator('app.telegram_destination')]
        private readonly iterable $destinations,
        private readonly TelegramClassifier $classifier,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $seen,
        #[Autowire(service: 'limiter.telegram_inbound')]
        private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Si hay bot: sin token, nada que atender. */
    public function isEnabled(): bool
    {
        return $this->api->isEnabled();
    }

    /**
     * Atiende un «update» de Telegram.
     *
     * @param array<string, mixed> $update Tal como lo manda Telegram.
     */
    public function handle(array $update): void
    {
        if (!$this->firstTime($update)) {
            return;
        }

        try {
            $this->attend($update);
        } catch (\Throwable $e) {
            // Sin la excepción entera: sólo su clase y mensaje, que nunca llevan el
            // token (lo que habla con Telegram ya los limpia).
            $this->logger->error('Telegram: fallo al atender un mensaje', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e::class . ': ' . $e->getMessage(),
            ]);
            $chatId = $update['message']['chat']['id'] ?? null;
            if ($chatId !== null && ($update['message']['chat']['type'] ?? null) === 'private') {
                (new TelegramChat($this->api, (string) $chatId, $this->logger))->say('Algo ha fallado al guardar lo que me has mandado. Vuelve a mandármelo dentro de un rato.');
            }
        }
    }

    /**
     * Si este mensaje no se había atendido ya, y lo apunta como atendido. Sin
     * `update_id` no hay forma de saberlo y se atiende.
     *
     * @param array<string, mixed> $update Tal como lo manda Telegram.
     */
    private function firstTime(array $update): bool
    {
        if (!isset($update['update_id'])) {
            return true;
        }

        $item = $this->seen->getItem('telegram_update_' . (int) $update['update_id']);
        if ($item->isHit()) {
            return false;
        }
        $this->seen->save($item->set(true)->expiresAfter(self::SEEN_SECONDS));

        return true;
    }

    /**
     * Lo común a todo mensaje y el reparto a su destino.
     *
     * @param array<string, mixed> $update Tal como lo manda Telegram.
     */
    private function attend(array $update): void
    {
        $message = $update['message'] ?? null;
        // Sólo chats privados: en un grupo, «quién manda» no es tan obvio, y el bot
        // no tiene por qué estar en ninguno.
        if (!\is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || !isset($message['from']['id'], $message['chat']['id'])) {
            return;
        }

        $chat = new TelegramChat($this->api, (string) $message['chat']['id'], $this->logger);
        $from = $message['from'];
        $text = trim((string) ($message['text'] ?? ''));

        // Por cuenta de Telegram, vinculada o no: frena un bucle, una cuenta robada
        // y a quien insista desde fuera. A un desconocido no se le contesta.
        if (!$this->limiter->create((string) $from['id'])->consume()->isAccepted()) {
            if ($this->links->findLinked((string) $from['id']) !== null) {
                $chat->say('Me has mandado muchas cosas en poco rato. Espera un poco y vuelve a intentarlo.');
            }

            return;
        }

        if (preg_match('~^/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]+))?$~', $text, $m) === 1) {
            $chat->say($this->start($from, $m[1] ?? null));

            return;
        }

        $link = $this->links->findLinked((string) $from['id']);
        if ($link === null || !$link->getUser()->isEnabled()) {
            $chat->say(self::STRANGER);

            return;
        }

        $input = TelegramInput::fromMessage($message);
        if ($input === null) {
            $chat->say($this->help($link->getUser()));

            return;
        }

        $input->setDownloader(fn (string $fileId): string => $this->api->download($fileId));
        try {
            $this->dispatch($input, $link, $chat);
        } finally {
            $input->cleanUp();
        }
    }

    /**
     * Elige destino y se lo pasa.
     *
     * @param TelegramInput $input Lo recibido.
     * @param TelegramLink  $link  Quién lo manda.
     * @param TelegramChat  $chat  Para contestar.
     */
    private function dispatch(TelegramInput $input, TelegramLink $link, TelegramChat $chat): void
    {
        $user = $link->getUser();
        $candidates = [];
        foreach ($this->destinations as $destination) {
            if ($destination->accepts($input, $user)) {
                $candidates[] = $destination;
            }
        }

        if ($candidates === []) {
            $chat->say("Eso no sé qué hacer con ello.\n\n" . $this->help($user));

            return;
        }

        $chosen = $candidates[0];
        if (\count($candidates) > 1) {
            if (!$this->classifier->isAvailable()) {
                $chat->say('Ahora mismo no puedo distinguir qué es lo que me mandas. Avisa a quien lleva la web.');

                return;
            }
            try {
                $chosen = $this->classifier->choose($input, $candidates);
            } catch (GeminiException|TelegramApiException $e) {
                $this->logger->warning('Telegram: no se pudo clasificar un mensaje', ['error' => $e->getMessage()]);
                $chat->say('Ahora mismo no puedo mirarlo. Vuelve a mandármelo dentro de un rato.');

                return;
            }
            if ($chosen === null) {
                $chat->say("No sé qué es esto, así que no lo he guardado.\n\n" . $this->help($user));

                return;
            }
        }

        $chosen->handle($input, $link, $chat);
    }

    /**
     * El /start: con código de invitación, vincula; sin él, saluda.
     *
     * @param array<string, mixed> $from Quién escribe.
     * @param string|null          $code Código del enlace.
     *
     * @return string Respuesta.
     */
    private function start(array $from, ?string $code): string
    {
        $telegramId = (string) $from['id'];
        $now = new \DateTimeImmutable();

        if ($code === null) {
            $link = $this->links->findLinked($telegramId);

            return $link !== null && $link->getUser()->isEnabled()
                ? sprintf("Hola, %s.\n\n%s", $link->getUser()->getDisplayName(), $this->help($link->getUser()))
                : self::STRANGER;
        }

        $invitation = $this->links->findByInvitationCode($code);
        $expired = 'Ese enlace ya no vale: o ha caducado o ya se usó. Pide uno nuevo a quien lleva la web de la asociación.';
        if ($invitation === null || !$invitation->isInvitationValid($now)) {
            return $expired;
        }

        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $linked = $this->em->wrapInTransaction(function () use ($invitation, $code, $telegramId, $name, $now): bool {
            // Gastar la invitación es lo primero y es condicional: si dos personas
            // abren el mismo enlace a la vez, sólo una consigue gastarlo.
            if (!$this->links->spendInvitation($invitation, $code, $now)) {
                return false;
            }

            // Una cuenta de Telegram es de una persona. Si ya estaba vinculada a
            // otra, manda la invitación nueva: alguien de la gestión la ha hecho a
            // propósito. En la misma transacción: o se mueve entera o no se mueve.
            $previous = $this->links->findLinked($telegramId);
            if ($previous !== null && $previous !== $invitation) {
                $this->em->remove($previous);
                $this->em->flush();
            }

            $invitation->link($telegramId, $name !== '' ? $name : null, $now);
            $this->em->flush();

            return true;
        });
        if (!$linked) {
            return $expired;
        }

        return sprintf("Hola, %s. Ya estás dado de alta.\n\n%s", $invitation->getUser()->getDisplayName(), $this->help($invitation->getUser()));
    }

    /**
     * Qué se le puede mandar, según lo que esté encendido y lo que esta persona
     * pueda usar.
     *
     * @param User $user Quién pregunta.
     */
    private function help(User $user): string
    {
        $lines = [];
        foreach ($this->destinations as $destination) {
            $line = $destination->help($user);
            if ($line !== null) {
                $lines[] = '• ' . $line;
            }
        }

        return $lines === []
            ? 'Ahora mismo no tengo nada encendido para ti. Pregunta a quien lleva la web de la asociación.'
            : "Esto es lo que puedes mandarme:\n" . implode("\n", $lines);
    }
}
