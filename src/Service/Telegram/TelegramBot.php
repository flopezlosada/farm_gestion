<?php

namespace App\Service\Telegram;

use App\Entity\TelegramLink;
use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Repository\TelegramSubmissionRepository;
use App\Service\Ai\GeminiException;
use App\Service\Telegram\Destination\CommandDestination;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\Destination\TelegramRejected;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * La puerta del bot: a cada mensaje le aplica lo común (sólo chats privados,
 * vincular con una invitación, no atender a quien no está vinculado) y lo manda a
 * su destino ({@see TelegramDestination}: facturas, y lo que venga).
 *
 * CÓMO SE ELIGE DESTINO:
 * - Se quedan los que esa persona tiene permiso para usar
 *   ({@see TelegramPermissions}) y que aceptan esa clase de mensaje. Si no queda
 *   ninguno, se dice y no se guarda nada (ni se descarga).
 * - Si no, el envío se guarda primero tal cual ({@see TelegramSubmission}), para
 *   poder corregir después adónde va.
 * - Opina {@see TelegramClassifier}, aunque sólo quede un destino: puede decir que
 *   no es de ninguno. Si lo tiene claro, va ahí; si duda (o no puede opinar), se
 *   le pregunta a quien lo mandó con botones, y mientras tanto queda pendiente en
 *   la web.
 * - Vaya donde vaya sin preguntar, se le cuenta lo que se ha entendido con un
 *   botón «Es otra cosa», por si el bot se equivocó, y otro «Correcto» para
 *   darlo por bueno y que no quede nada por pulsar.
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
     * @param TelegramClassifier            $classifier   Para saber qué es lo que llega.
     * @param TelegramSubmissionRouter      $router       Adónde va cada envío, y cómo se corrige.
     * @param TelegramSubmissionRepository  $submissions  Los envíos, para los botones.
     * @param EntityManagerInterface        $em           Para guardar el vínculo.
     * @param CacheItemPoolInterface        $seen         Mensajes ya atendidos.
     * @param RateLimiterFactory            $limiter      Cuántos mensajes por cuenta y hora.
     * @param LoggerInterface               $logger       Rastro de lo que falla.
     */
    public function __construct(
        private readonly TelegramBotApi $api,
        private readonly TelegramLinkRepository $links,
        private readonly TelegramClassifier $classifier,
        private readonly TelegramSubmissionRouter $router,
        private readonly TelegramSubmissionRepository $submissions,
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
            $chat = $update['message']['chat'] ?? $update['callback_query']['message']['chat'] ?? [];
            $chatId = $chat['id'] ?? null;
            if ($chatId !== null && ($chat['type'] ?? null) === 'private') {
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
        if (\is_array($update['callback_query'] ?? null)) {
            $this->press($update['callback_query']);

            return;
        }

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

        $input = TelegramInput::fromMessage($message, isset($update['update_id']) ? (int) $update['update_id'] : null);
        if ($input === null) {
            $chat->say($this->help($link->getUser()));

            return;
        }

        $input->setDownloader(fn (string $fileId): string => $this->api->download($fileId));
        try {
            $this->dispatch($input, $link, $chat, (string) $message['chat']['id']);
        } finally {
            $input->cleanUp();
        }
    }

    /**
     * Guarda el envío y lo manda a su destino, o pregunta adónde va.
     *
     * @param TelegramInput $input  Lo recibido.
     * @param TelegramLink  $link   Quién lo manda.
     * @param TelegramChat  $chat   Para contestar.
     * @param string        $chatId Su chat.
     */
    private function dispatch(TelegramInput $input, TelegramLink $link, TelegramChat $chat, string $chatId): void
    {
        $user = $link->getUser();
        $candidates = $this->router->candidates($input, $user);
        if ($candidates === []) {
            $chat->say("Eso no sé qué hacer con ello.\n\n" . $this->help($user));

            return;
        }

        try {
            $submission = $this->router->record($input, $link, $chatId);
        } catch (TelegramApiException|TelegramRejected $e) {
            $this->logger->warning('Telegram: no se pudo guardar un envío', ['error' => $e->getMessage()]);
            $chat->say($e->getMessage());

            return;
        }

        // Ya está guardado: si algo falla de aquí en adelante, no se le pide que lo
        // reenvíe (saldría repetido), queda pendiente en la web.
        try {
            $this->place($submission, $input, $candidates);
        } catch (\Throwable $e) {
            $this->logger->error('Telegram: fallo al colocar un envío', ['submission' => $submission->getId(), 'error' => $e::class . ': ' . $e->getMessage()]);
            $chat->say('Algo ha fallado al guardarlo, pero lo tengo apuntado y lo revisarán desde la web. No hace falta que lo mandes otra vez.');
        }
    }

    /**
     * Lo coloca en su destino o pregunta adónde va.
     *
     * @param TelegramSubmission        $submission El envío, ya guardado.
     * @param TelegramInput             $input      Lo recibido.
     * @param list<TelegramDestination> $candidates Destinos que esta persona puede usar para esto (uno o más).
     */
    private function place(TelegramSubmission $submission, TelegramInput $input, array $candidates): void
    {
        // Una orden con su formato («caja huerta 400,56») no se clasifica: se sabe qué
        // es por cómo está escrita. Y si no se puede atender, es un error de esa orden
        // (un importe dudoso, una caja que no existe): se dice, sin ofrecer otros
        // apartados que no tienen nada que ver.
        $commands = array_values(array_filter($candidates, static fn (TelegramDestination $d): bool => $d instanceof CommandDestination));
        if (\count($commands) === 1) {
            $refused = $this->router->route($submission, $commands[0]);
            if ($refused !== null) {
                $this->router->refuse($submission, $refused);
            }

            return;
        }

        // Se clasifica aunque sólo haya un apartado posible: con permisos por
        // apartado es lo normal, y mandarlo directo metería la foto de una lechuga
        // en la bandeja de facturas de quien sólo puede mandar facturas. El
        // clasificador puede contestar «ninguno», y entonces se pregunta.
        if (!$this->classifier->isAvailable()) {
            $this->router->ask($submission, $candidates, '¿Qué es esto?');

            return;
        }
        try {
            $classification = $this->classifier->classify($input, $candidates);
        } catch (GeminiException|TelegramApiException $e) {
            $this->logger->warning('Telegram: no se pudo clasificar un mensaje', ['error' => $e->getMessage()]);
            $this->router->ask($submission, $candidates, '¿Qué es esto?');

            return;
        }

        $guess = $classification->destination;
        if ($guess !== null && $classification->isClear()) {
            $this->routeAlone($submission, $guess, $candidates);

            return;
        }

        $this->router->ask(
            $submission,
            $guess !== null ? [$guess, ...$this->others($candidates, $guess)] : $candidates,
            $guess !== null
                ? sprintf('Creo que es «%s», pero no estoy seguro. ¿Qué es?', $guess->label())
                : 'No sé qué es esto. ¿Es alguna de estas cosas?',
        );
    }

    /**
     * Lo manda a un destino sin preguntar. Si ese destino no lo admite, se le
     * ofrecen los otros posibles; si no hay otros, se descarta diciendo por qué.
     *
     * @param TelegramSubmission        $submission  El envío.
     * @param TelegramDestination       $destination Adónde.
     * @param list<TelegramDestination> $candidates  Todos los posibles.
     */
    private function routeAlone(TelegramSubmission $submission, TelegramDestination $destination, array $candidates): void
    {
        $refused = $this->router->route($submission, $destination);
        if ($refused === null) {
            return;
        }

        $others = $this->others($candidates, $destination);
        if ($others === []) {
            $this->router->refuse($submission, $refused);

            return;
        }
        $this->router->ask($submission, $others, $refused . "\n\n¿Es alguna de estas cosas?");
    }

    /**
     * Los destinos de la lista menos uno.
     *
     * @param list<TelegramDestination> $candidates Todos.
     * @param TelegramDestination       $except     El que sobra.
     *
     * @return list<TelegramDestination>
     */
    private function others(array $candidates, TelegramDestination $except): array
    {
        return array_values(array_filter($candidates, static fn (TelegramDestination $d): bool => $d !== $except));
    }

    /**
     * Alguien ha pulsado un botón de un envío: colocarlo, descartarlo, «Es otra
     * cosa», «Estaba bien» o «Correcto». Sólo vale para quien lo mandó, y mientras siga
     * vinculado.
     *
     * @param array<string, mixed> $callback La pulsación, tal como la manda Telegram.
     */
    private function press(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        if ($callbackId === '' || $fromId === '' || ($callback['message']['chat']['type'] ?? null) !== 'private') {
            return;
        }
        if (!$this->limiter->create($fromId)->consume()->isAccepted()) {
            $this->answer($callbackId, 'Espera un poco y vuelve a intentarlo.');

            return;
        }

        $parsed = TelegramSubmissionRouter::parseCallback((string) ($callback['data'] ?? ''));
        $submission = $parsed !== null ? $this->submissions->find($parsed['id']) : null;
        $link = $this->links->findLinked($fromId);
        if ($parsed === null || $submission === null || $link === null || !$link->getUser()->isEnabled()
            || $submission->getUser()->getId() !== $link->getUser()->getId()) {
            $this->answer($callbackId, 'Este botón ya no sirve.');

            return;
        }

        // Se contesta pase lo que pase: si no, el botón se queda «cargando».
        $problem = 'Algo ha fallado. Inténtalo dentro de un rato.';
        try {
            $problem = match ($parsed['action']) {
                'o' => $this->offerAlternatives($submission),
                'k' => $this->showRouted($submission),
                'c' => $this->settle($submission),
                'x' => $this->router->discard($submission),
                'r' => $this->routeTo($submission, (string) $parsed['key']),
            };
        } finally {
            $this->answer($callbackId, $problem);
        }
    }

    /** «Es otra cosa»: los otros destinos posibles. */
    private function offerAlternatives(TelegramSubmission $submission): ?string
    {
        if (!$submission->isRouted()) {
            return 'Ese envío ya no está guardado en ningún sitio.';
        }
        $this->router->offerAlternatives($submission);

        return null;
    }

    /** «Estaba bien»: vuelve a lo que había. */
    private function showRouted(TelegramSubmission $submission): ?string
    {
        if ($submission->isRouted()) {
            $this->router->showRouted($submission);
        }

        return null;
    }

    /** «Correcto»: se quitan los botones; lo colocado se queda donde está. */
    private function settle(TelegramSubmission $submission): ?string
    {
        if ($submission->isRouted()) {
            $this->router->settle($submission);
        }

        return null;
    }

    /** Un botón con un destino: sólo uno de los que esta persona puede usar para esto. */
    private function routeTo(TelegramSubmission $submission, string $key): ?string
    {
        foreach ($this->router->candidatesFor($submission) as $destination) {
            if ($destination->key() === $key) {
                return $this->router->route($submission, $destination);
            }
        }

        return 'Eso ya no está disponible.';
    }

    /** Contesta a la pulsación; si falla, no importa: lo hecho, hecho está. */
    private function answer(string $callbackId, ?string $text): void
    {
        try {
            $this->api->answerCallbackQuery($callbackId, $text);
        } catch (TelegramApiException $e) {
            $this->logger->info('Telegram: no se pudo responder a un botón', ['error' => $e->getMessage()]);
        }
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
        foreach ($this->router->allowed($user) as $destination) {
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
