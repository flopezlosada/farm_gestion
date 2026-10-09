<?php

namespace App\Service\Telegram;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Service\Ai\GeminiException;
use App\Service\Telegram\Destination\TelegramDestination;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

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
 */
class TelegramBot
{
    private const STRANGER = 'Este bot es de la CSA Vega de Jarama y sólo atiende a su gente. Para usarlo, pide tu enlace a quien lleva la web de la asociación.';

    /**
     * @param TelegramBotApi                $api          API del bot.
     * @param TelegramLinkRepository        $links        Quién está vinculado.
     * @param iterable<TelegramDestination> $destinations Lo que sabe hacer.
     * @param TelegramClassifier            $classifier   Para elegir cuando hay varios.
     * @param EntityManagerInterface        $em           Para guardar el vínculo.
     * @param LoggerInterface               $logger       Rastro de lo que falla.
     */
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly TelegramLinkRepository $links,
        #[AutowireIterator('app.telegram_destination')]
        private readonly iterable $destinations,
        private readonly TelegramClassifier $classifier,
        private readonly EntityManagerInterface $em,
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
        $message = $update['message'] ?? null;
        // Sólo chats privados: en un grupo, «quién manda» no es tan obvio, y el bot
        // no tiene por qué estar en ninguno.
        if (!\is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || !isset($message['from']['id'], $message['chat']['id'])) {
            return;
        }

        $chat = new TelegramChat($this->api, (string) $message['chat']['id'], $this->logger);
        $from = $message['from'];
        $text = trim((string) ($message['text'] ?? ''));

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
        if ($invitation === null || !$invitation->isInvitationValid($now)) {
            return 'Ese enlace ya no vale: o ha caducado o ya se usó. Pide uno nuevo a quien lleva la web de la asociación.';
        }

        // Una cuenta de Telegram es de una persona. Si ya estaba vinculada a otra,
        // manda la invitación nueva: alguien de la gestión la ha hecho a propósito.
        $previous = $this->links->findLinked($telegramId);
        if ($previous !== null && $previous !== $invitation) {
            $this->em->remove($previous);
            $this->em->flush();
        }

        $name = trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $invitation->link($telegramId, $name !== '' ? $name : null, $now);
        $this->em->flush();

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
