<?php

namespace App\Service\Telegram\Destination;

use App\Entity\CashCount;
use App\Entity\User;
use App\Service\Accounting\CashCount\CashCountMessage;
use App\Service\Accounting\CashCount\CashCountRecorder;
use App\Service\Accounting\CashCount\CashCountRejected;
use App\Service\Accounting\CashCount\DayBalances;
use App\Service\Accounting\CashCount\DictatedCashCountReader;
use App\Service\Accounting\CashCount\RecordedCashCount;
use App\Service\Ai\GeminiException;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramInput;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * El arqueo mensual de una caja de efectivo: quien la ha contado escribe «caja huerta
 * 400,56» (y la fecha detrás si no es de hoy: «caja huerta 400,56 30/10») o lo dice en
 * una nota de voz, y el bot lo compara con el libro. Cuadre o no, queda registrado; si
 * no cuadra, la portada de contabilidad lo avisa hasta que se anote lo que falta.
 *
 * Escrito, es una orden con formato fijo ({@see CommandDestination}): se lee sin IA,
 * porque el importe de una caja no se adivina. Dicho, Gemini lo pone por escrito con ese
 * mismo formato ({@see DictatedCashCountReader}) y sigue igual.
 *
 * Nada se registra sin que quien contó confirme lo entendido ({@see ConfirmedDestination}):
 * un importe mal oído o mal tecleado quedaría como un descuadre en la portada. Lo que se
 * le enseña es sólo lo que ha dicho (caja, día, importe), no lo que dice el libro: el
 * recuento se hace a ciegas.
 */
class CashCountDestination implements CommandDestination, ConfirmedDestination
{
    /**
     * @param CashCountRecorder       $recorder Quien lo compara y lo guarda.
     * @param DictatedCashCountReader $reader   Quien entiende las notas de voz.
     * @param EntityManagerInterface  $em       Para deshacerlo.
     * @param AppSettings             $settings Para saber si contabilidad está encendida.
     * @param LoggerInterface         $logger   Rastro de lo que falla.
     */
    public function __construct(
        private readonly CashCountRecorder $recorder,
        private readonly DictatedCashCountReader $reader,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'cash_count';
    }

    public function label(): string
    {
        return 'Arqueo de caja';
    }

    public function section(): string
    {
        return 'contabilidad';
    }

    public function description(): string
    {
        return 'Recuento del dinero que hay en una caja de efectivo: de qué caja es y cuánto se ha contado («caja de la huerta, cuatrocientos con cincuenta»). No es dinero que pase de una caja a otra, ni un gasto.';
    }

    public function accepts(TelegramInput $input, User $user): bool
    {
        return $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)
            && ($this->recognizes($input) || ($input->kind === TelegramInput::VOICE && $this->reader->isAvailable()));
    }

    public function recognizes(TelegramInput $input): bool
    {
        return $input->kind === TelegramInput::TEXT && CashCountMessage::looksLikeOne($input->text);
    }

    public function help(User $user): ?string
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)) {
            return null;
        }

        $voice = $this->reader->isAvailable() ? ', o dímelo en una nota de voz' : '';

        return sprintf('Arqueo de caja: cuenta el dinero y escríbeme la caja y el importe («caja huerta 400,56»; si no es de hoy, con la fecha detrás: «caja huerta 400,56 30/10»)%s. Te enseño lo que he entendido, lo confirmas y te digo si cuadra con el libro.', $voice);
    }

    public function preview(TelegramInput $input, User $user): TelegramPreview
    {
        $written = $this->written($input);
        try {
            $message = CashCountMessage::parse($written, self::dayOf($input));
            $account = $this->recorder->check($message);
        } catch (CashCountRejected $e) {
            throw new TelegramRejected($e->getMessage());
        }

        return new TelegramPreview(
            sprintf(
                '%s, %s: %s contados. ¿Está bien? Si no, pulsa «No» y mándamelo otra vez.',
                $account->getName(),
                $message->date->format('d/m/Y'),
                DayBalances::euros($message->countedCents),
            ),
            $input->kind === TelegramInput::VOICE ? $written : null,
        );
    }

    public function commit(TelegramInput $input, User $user): TelegramOutcome
    {
        $written = $this->written($input);
        try {
            $recorded = $this->recorder->record(
                CashCountMessage::parse($written, self::dayOf($input)),
                $user,
                $input->sentAt,
                $written,
                $input->updateId,
            );
        } catch (CashCountRejected $e) {
            throw new TelegramRejected($e->getMessage());
        }

        return new TelegramOutcome((string) $recorded->count->getId(), self::summarize($recorded));
    }

    /**
     * El arqueo por escrito: lo escrito; de una nota de voz, lo que ya se le enseñó a
     * quien la mandó o, si no se le enseñó (se coloca desde la web), lo que entienda
     * ahora Gemini.
     *
     * @throws TelegramRejected Si la nota de voz no se entiende o no se puede descargar.
     */
    private function written(TelegramInput $input): string
    {
        if ($input->kind !== TelegramInput::VOICE) {
            return $input->text;
        }
        if ($input->transcript !== null) {
            return $input->transcript;
        }

        try {
            return $this->reader->read(Dictation::parts($input, $this->logger), $this->recorder->openCashAccounts(), self::dayOf($input));
        } catch (CashCountRejected $e) {
            throw new TelegramRejected($e->getMessage());
        } catch (GeminiException $e) {
            $this->logger->warning('Telegram: no se pudo entender un arqueo dicho', ['error' => $e->getMessage()]);
            throw new TelegramRejected('Ahora mismo no puedo entender notas de voz. Escríbemelo: «caja huerta 400,56».');
        }
    }

    /**
     * El día en que se mandó: el del recuento si no se dice otro, y el último que puede
     * ser. No el de hoy: si se coloca días después desde la web, el arqueo sigue siendo
     * del día en que se contó.
     */
    private static function dayOf(TelegramInput $input): \DateTimeImmutable
    {
        return $input->sentAt->setTime(0, 0);
    }

    /**
     * «Es otra cosa»: el mensaje no era un arqueo, así que no se queda. No es corregir
     * un arqueo (eso es mandar otro): es que nunca lo fue.
     */
    public function undo(string $ref): ?string
    {
        $count = $this->em->find(CashCount::class, (int) $ref);
        if ($count !== null) {
            $this->em->remove($count);
            $this->em->flush();
        }

        return null;
    }

    /**
     * Lo que se le contesta a quien contó: la caja, el día, lo contado y si cuadra. Si
     * ese día hay apuntes, los dos saldos (no se sabe si contó antes o después de
     * ellos). Si no cuadra, la diferencia y qué se hace con ella. Corto: el envío lo
     * guarda en 255 caracteres.
     *
     * @param RecordedCashCount $recorded El arqueo y el libro con el que se comparó.
     */
    public static function summarize(RecordedCashCount $recorded): string
    {
        $count = $recorded->count;
        $balances = $recorded->balances;
        $head = sprintf('%s, %s: contados %s.', $count->getAccount()->getName(), $count->getCountDate()->format('d/m/Y'), DayBalances::euros($count->getCountedCents()));
        $book = $balances->changesThatDay()
            ? sprintf('En el libro: %s antes de lo anotado ese día y %s con ello.', DayBalances::euros($balances->openingCents), DayBalances::euros($balances->closingCents))
            : sprintf('En el libro: %s.', DayBalances::euros($balances->closingCents));

        if ($recorded->squares()) {
            return sprintf('%s %s Cuadra: queda registrado.', $head, $book);
        }

        $difference = $count->getCountedCents() - $balances->closingCents;

        return sprintf(
            '%s %s No cuadra: %s %s. Lo aviso en la portada de contabilidad: suele ser un ticket o un traspaso sin anotar.',
            $head,
            $book,
            $difference < 0 ? 'faltan' : 'sobran',
            DayBalances::euros(abs($difference)),
        );
    }
}
