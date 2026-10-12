<?php

namespace App\Service\Telegram;

use App\Entity\TelegramLink;
use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\Destination\TelegramRejected;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Mime\MimeTypes;

/**
 * Adónde va cada envío al bot, y cómo se corrige. Lo usan igual el bot (al llegar
 * el mensaje y al pulsar un botón) y la web (la bandeja de envíos), para que
 * «mandar a facturas», «es otra cosa» y «descartar» hagan lo mismo vengan de donde
 * vengan.
 *
 * Cada cambio se le cuenta a quien lo mandó en su chat, en el mismo mensaje que
 * llevaba los botones: así no le quedan botones viejos que ya no hacen nada.
 */
class TelegramSubmissionRouter
{
    /** Dato de los botones: `s:<id>:<acción>[:<apartado>]`. Telegram admite hasta 64 bytes. */
    private const CALLBACK_PREFIX = 's';

    /**
     * @param iterable<TelegramDestination> $destinations Los apartados.
     * @param TelegramFileStore             $files        Copia de los ficheros de cada envío.
     * @param TelegramBotApi                $api          Para contestar a quien lo mandó.
     * @param EntityManagerInterface        $em           Para guardar.
     * @param LoggerInterface               $logger       Rastro de lo que falla.
     */
    public function __construct(
        #[AutowireIterator('app.telegram_destination')]
        private readonly iterable $destinations,
        private readonly TelegramFileStore $files,
        private readonly TelegramBotApi $api,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Guarda lo que acaba de llegar, con su propia copia del fichero, a la espera
     * de saber adónde va.
     *
     * @param TelegramInput $input Lo recibido.
     * @param TelegramLink  $link  Quién lo manda.
     * @param string        $chatId Su chat.
     *
     * @throws TelegramApiException Si el fichero no se puede descargar de Telegram.
     * @throws TelegramRejected     Si es de un formato que no se guarda.
     */
    public function record(TelegramInput $input, TelegramLink $link, string $chatId): TelegramSubmission
    {
        $submission = new TelegramSubmission($link->getUser(), $chatId, $input->kind, $input->text, $input->sentAt);
        if ($input->hasFile()) {
            $path = $input->localPath();
            $mimeType = MimeTypes::getDefault()->guessMimeType($path);
            if (!\in_array($mimeType, TelegramFileStore::MIME_TYPES, true)) {
                throw new TelegramRejected('Ese formato no lo guardo. Vale un PDF, una foto, una nota de voz o un documento (DOCX, ODT, XLSX, ODS).');
            }
            $submission->attachFile($this->files->keepCopy($path, (string) $input->fileName), (string) $input->fileName, $mimeType);
        }
        $this->em->persist($submission);
        $this->em->flush();

        return $submission;
    }

    /**
     * Los apartados a los que podría ir este envío, según su forma y quién lo mandó.
     *
     * @return list<TelegramDestination>
     */
    public function candidatesFor(TelegramSubmission $submission): array
    {
        return $this->candidates($this->inputFor($submission), $submission->getUser());
    }

    /**
     * Los apartados a los que podría ir lo recibido, según su forma y quién lo manda.
     *
     * @param TelegramInput $input Lo recibido.
     * @param User          $user  Quién lo manda.
     *
     * @return list<TelegramDestination>
     */
    public function candidates(TelegramInput $input, User $user): array
    {
        $found = [];
        foreach ($this->destinations as $destination) {
            if ($destination->accepts($input, $user)) {
                $found[] = $destination;
            }
        }

        return $found;
    }

    /** El apartado de esa clave, o null si ya no existe. */
    public function destination(string $key): ?TelegramDestination
    {
        foreach ($this->destinations as $destination) {
            if ($destination->key() === $key) {
                return $destination;
            }
        }

        return null;
    }

    /**
     * Lo manda a un apartado. Si ya estaba en otro, primero lo deshace allí; si
     * allí ya no se puede deshacer, no se toca nada.
     *
     * Todo dentro de una transacción con la fila del envío bloqueada: dos
     * pulsaciones a la vez (un doble toque, o Telegram y la web) se atienden una
     * detrás de otra, y la segunda ve lo que hizo la primera en vez de crear otra
     * factura. Y si el apartado falla a medias (base de datos, Gemini…), se vuelve
     * entero a como estaba, deshacer incluido. Lo que se le dice por Telegram va
     * después, fuera de la transacción: no se tiene la fila bloqueada esperando a
     * Telegram.
     *
     * @param TelegramSubmission  $submission  El envío.
     * @param TelegramDestination $destination Adónde.
     * @param User|null           $by          Quién lo decide desde la web; null si es por Telegram.
     *
     * @return string|null Null si ha ido bien; si no, por qué (para quien lo pidió).
     */
    public function route(TelegramSubmission $submission, TelegramDestination $destination, ?User $by = null): ?string
    {
        $result = $this->em->wrapInTransaction(function () use ($submission, $destination, $by): array {
            $this->em->refresh($submission, LockMode::PESSIMISTIC_WRITE);
            if ($submission->getStatus() === TelegramSubmission::STATUS_DISCARDED) {
                return ['problem' => 'Ese envío ya se descartó.', 'changed' => false];
            }
            if ($submission->isRouted() && $submission->getDestination() === $destination->key()) {
                return ['problem' => null, 'changed' => false];
            }

            $refused = $this->undoCurrent($submission);
            if ($refused !== null) {
                return ['problem' => $refused, 'changed' => false, 'settled' => true];
            }

            $input = $this->inputFor($submission);
            try {
                $outcome = $destination->commit($input, $submission->getUser());
            } catch (TelegramRejected $e) {
                // Deshecho donde estaba y rechazado aquí: queda pendiente, con el motivo.
                $submission->askAbout($destination->label() . ': ' . $e->getMessage());
                $this->em->flush();

                return ['problem' => $e->getMessage(), 'changed' => false];
            } finally {
                $input->cleanUp();
            }

            $submission->routeTo($destination->key(), $outcome->ref, $outcome->summary, $by, new \DateTimeImmutable());
            $this->em->flush();

            return ['problem' => null, 'changed' => true, 'doubt' => $outcome->doubt];
        });

        if ($result['changed']) {
            // Si lo ha colocado una persona desde la web, no hay duda que preguntar.
            $doubt = $by === null ? $result['doubt'] : null;
            $doubt !== null ? $this->askToKeep($submission, $doubt) : $this->tellRouted($submission, $by);
        }
        $this->tellSettled($submission, $result);

        return $result['problem'];
    }

    /**
     * No era nada que guardar. Si estaba en un apartado, se deshace allí antes. El
     * fichero se conserva, como en la bandeja de facturas: descartar no es borrar.
     * Con la fila bloqueada, por lo mismo que {@see route()}.
     *
     * @param TelegramSubmission $submission El envío.
     * @param User|null          $by         Quién lo decide desde la web; null si es por Telegram.
     *
     * @return string|null Null si ha ido bien; si no, por qué.
     */
    public function discard(TelegramSubmission $submission, ?User $by = null): ?string
    {
        $result = $this->em->wrapInTransaction(function () use ($submission, $by): array {
            $this->em->refresh($submission, LockMode::PESSIMISTIC_WRITE);
            if ($submission->getStatus() === TelegramSubmission::STATUS_DISCARDED) {
                return ['problem' => null, 'changed' => false];
            }
            $refused = $this->undoCurrent($submission);
            if ($refused !== null) {
                return ['problem' => $refused, 'changed' => false, 'settled' => true];
            }

            $submission->discard($by === null ? 'Descartado por quien lo mandó.' : 'Descartado desde la web.', $by, new \DateTimeImmutable());
            $this->em->flush();

            return ['problem' => null, 'changed' => true];
        });

        if ($result['changed']) {
            $text = $by === null
                ? 'Vale, no lo guardo.'
                : sprintf('Lo que mandaste el %s lo han descartado desde la web: no se ha guardado.', $submission->getSentAt()->format('d/m/Y'));
            $submission->setBotMessageId($this->chat($submission)->replace($submission->getBotMessageId(), $this->quote($submission) . $text));
            $this->em->flush();
        }
        $this->tellSettled($submission, $result);

        return $result['problem'];
    }

    /**
     * El único apartado posible no lo admite (un formato que no guarda): se
     * descarta, se borra su copia (no hay nada que conservar) y se le dice por qué.
     *
     * @param TelegramSubmission $submission El envío, recién llegado.
     * @param string             $reason     Lo que ha dicho el apartado.
     */
    public function refuse(TelegramSubmission $submission, string $reason): void
    {
        $submission->discard($reason, null, new \DateTimeImmutable());
        $this->em->flush();
        $this->files->discard($submission->getFileName());
        $this->chat($submission)->replace($submission->getBotMessageId(), $reason);
    }

    /**
     * Le pregunta a quien lo mandó adónde va, con un botón por apartado posible
     * (el que el bot cree más probable, primero) y uno para descartarlo.
     *
     * @param TelegramSubmission        $submission El envío.
     * @param list<TelegramDestination> $options    Apartados a ofrecer, en orden.
     * @param string                    $question   La pregunta.
     */
    public function ask(TelegramSubmission $submission, array $options, string $question): void
    {
        $submission->askAbout($question);
        $this->em->flush();

        $messageId = $this->chat($submission)->replace(
            $submission->getBotMessageId(),
            $question,
            $this->optionButtons($submission, $options),
        );
        $submission->setBotMessageId($messageId);
        $this->em->flush();
    }

    /**
     * Ofrece los OTROS apartados posibles, para corregir al bot («Es otra cosa»).
     *
     * @param TelegramSubmission $submission El envío, ya en un apartado.
     */
    public function offerAlternatives(TelegramSubmission $submission): void
    {
        $others = $this->others($submission);

        $this->chat($submission)->replace(
            $submission->getBotMessageId(),
            $this->quote($submission) . '¿Qué es entonces?',
            [...$this->optionButtons($submission, $others), [['« Estaba bien', $this->callback($submission, 'k')]]],
        );
    }

    /**
     * Los apartados posibles para este envío, menos en el que está.
     *
     * @return list<TelegramDestination>
     */
    private function others(TelegramSubmission $submission): array
    {
        return array_values(array_filter(
            $this->candidatesFor($submission),
            static fn (TelegramDestination $d): bool => $d->key() !== $submission->getDestination(),
        ));
    }

    /**
     * Vuelve a enseñar lo hecho con su botón «Es otra cosa» (tras «Estaba bien»).
     *
     * @param TelegramSubmission $submission El envío, ya en un apartado.
     */
    public function showRouted(TelegramSubmission $submission): void
    {
        $this->tellRouted($submission, null);
    }

    /**
     * «Correcto»: deja el mensaje como está pero sin botones, para que no quede una
     * corrección a medio decidir ni se pueda pulsar por error más adelante. Corregirlo
     * sigue siendo posible desde la web.
     *
     * @param TelegramSubmission $submission El envío, ya en un apartado.
     */
    public function settle(TelegramSubmission $submission): void
    {
        $this->chat($submission)->replace($submission->getBotMessageId(), (string) $submission->getSummary());
    }

    /**
     * Lee el dato de un botón.
     *
     * @param string $data Lo que manda Telegram al pulsarlo.
     *
     * @return array{id: int, action: string, key: string|null}|null Null si no es de aquí.
     */
    public static function parseCallback(string $data): ?array
    {
        if (preg_match('~^' . self::CALLBACK_PREFIX . ':(\d+):([orxkc])(?::([a-z0-9_-]{1,40}))?$~', $data, $m) !== 1) {
            return null;
        }

        return ['id' => (int) $m[1], 'action' => $m[2], 'key' => $m[3] ?? null];
    }

    /**
     * Si a quien lo mandó le ha llegado el último aviso sobre este envío. Tras
     * colocarlo o descartarlo, el mensaje del bot es el de ese aviso; si no se pudo
     * mandar (sin bot, Telegram caído, bot bloqueado), no queda ninguno.
     */
    public static function wasTold(TelegramSubmission $submission): bool
    {
        return $submission->getBotMessageId() !== null;
    }

    /**
     * Lo que se le dice a quien lo mandó cuando ya está en su apartado, con el botón
     * para corregirlo.
     *
     * @param TelegramSubmission $submission El envío.
     * @param User|null          $by         Quién lo encaminó desde la web, si fue así.
     */
    private function tellRouted(TelegramSubmission $submission, ?User $by): void
    {
        $text = (string) $submission->getSummary();
        if ($by !== null) {
            $text = sprintf('Lo que mandaste el %s lo han colocado desde la web. %s', $submission->getSentAt()->format('d/m/Y'), $text);
        }

        $messageId = $this->chat($submission)->replace(
            $submission->getBotMessageId(),
            $text,
            [[['✓ Correcto', $this->callback($submission, 'c')], ['Es otra cosa', $this->callback($submission, 'o')]]],
        );
        $submission->setBotMessageId($messageId);
        $this->em->flush();
    }

    /**
     * El apartado lo ha guardado pero duda de que sea para él: en vez de «hecho», se
     * pregunta. Guardarlo igualmente es lo mismo que «Correcto» (se quitan los
     * botones y se queda donde está); si no, a otro apartado o se descarta.
     *
     * @param TelegramSubmission $submission El envío, ya en el apartado que duda.
     * @param string             $doubt      La pregunta, tal como la formula el apartado.
     */
    private function askToKeep(TelegramSubmission $submission, string $doubt): void
    {
        $messageId = $this->chat($submission)->replace(
            $submission->getBotMessageId(),
            $doubt,
            [[['Guárdalo igualmente', $this->callback($submission, 'c')]], ...$this->optionButtons($submission, $this->others($submission))],
        );
        $submission->setBotMessageId($messageId);
        $this->em->flush();
    }

    /**
     * Si el apartado se ha negado a deshacerlo (ya resuelto en la web: una factura
     * anotada o descartada), ya no hay nada que corregir desde el móvil: el mensaje
     * lo dice y se queda sin botones, en vez de seguir ofreciendo uno que no sirve.
     *
     * @param TelegramSubmission                                        $submission El envío, que sigue donde estaba.
     * @param array{problem: string|null, changed: bool, settled?: bool} $result     Lo que pasó en la transacción.
     */
    private function tellSettled(TelegramSubmission $submission, array $result): void
    {
        if (!($result['settled'] ?? false)) {
            return;
        }

        $messageId = $this->chat($submission)->replace(
            $submission->getBotMessageId(),
            $submission->getSummary() . "\n\n" . $result['problem'],
        );
        $submission->setBotMessageId($messageId);
        $this->em->flush();
    }

    /**
     * Deshace el envío en el apartado donde esté, si está en alguno.
     *
     * @return string|null Null si no estaba en ninguno o se ha deshecho; si no, por qué.
     */
    private function undoCurrent(TelegramSubmission $submission): ?string
    {
        if (!$submission->isRouted()) {
            return null;
        }

        $current = $this->destination((string) $submission->getDestination());
        if ($current === null) {
            // El apartado ya no existe: no hay nada que deshacer.
            return null;
        }

        return $current->undo((string) $submission->getTargetRef());
    }

    /**
     * El envío tal como llegó, con una copia de su fichero que el apartado se
     * puede llevar.
     */
    private function inputFor(TelegramSubmission $submission): TelegramInput
    {
        $fileName = $submission->getFileName();
        $input = new TelegramInput(
            $submission->getKind(),
            $submission->getText(),
            $submission->getSentAt(),
            $fileName !== null ? 'stored:' . $fileName : null,
            $submission->getOriginalName(),
            0,
            $submission->getMimeType(),
        );
        if ($fileName !== null) {
            $input->setDownloader(function () use ($fileName): string {
                return $this->files->temporaryCopy($fileName)
                    ?? throw new TelegramApiException('El fichero de este envío ya no está guardado.');
            });
        }

        return $input;
    }

    /**
     * Un botón por apartado y uno para descartar.
     *
     * @param list<TelegramDestination> $options Apartados.
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    private function optionButtons(TelegramSubmission $submission, array $options): array
    {
        $rows = array_map(
            fn (TelegramDestination $d): array => [[$d->label(), $this->callback($submission, 'r', $d->key())]],
            $options,
        );
        $rows[] = [['Nada de esto: descártalo', $this->callback($submission, 'x')]];

        return $rows;
    }

    private function callback(TelegramSubmission $submission, string $action, ?string $key = null): string
    {
        return implode(':', array_filter([self::CALLBACK_PREFIX, (string) $submission->getId(), $action, $key], static fn ($v) => $v !== null));
    }

    /** Para recordar de qué envío se habla: el nombre del fichero o el principio del texto. */
    private function quote(TelegramSubmission $submission): string
    {
        $what = $submission->getOriginalName() ?? mb_strimwidth($submission->getText(), 0, 60, '…');

        return $what !== '' ? '«' . $what . '»: ' : '';
    }

    private function chat(TelegramSubmission $submission): TelegramChat
    {
        return new TelegramChat($this->api, $submission->getChatId(), $this->logger);
    }
}
