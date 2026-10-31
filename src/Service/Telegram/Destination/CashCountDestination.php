<?php

namespace App\Service\Telegram\Destination;

use App\Entity\CashCount;
use App\Entity\User;
use App\Service\Accounting\CashCount\CashCountMessage;
use App\Service\Accounting\CashCount\CashCountRecorder;
use App\Service\Accounting\CashCount\CashCountRejected;
use App\Service\Accounting\CashCount\DayBalances;
use App\Service\Accounting\CashCount\RecordedCashCount;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El arqueo mensual de una caja de efectivo: quien la ha contado escribe «caja huerta
 * 400,56» (y la fecha detrás si no es de hoy: «caja huerta 400,56 30/10»), y el bot lo
 * compara con el libro. Cuadre o no, queda registrado; si no cuadra, la portada de
 * contabilidad lo avisa hasta que se anote lo que falta.
 *
 * Es una orden con formato fijo ({@see CommandDestination}): se lee sin IA, porque el
 * importe de una caja no se adivina.
 */
class CashCountDestination implements CommandDestination
{
    /**
     * @param CashCountRecorder      $recorder Quien lo compara y lo guarda.
     * @param EntityManagerInterface $em       Para deshacerlo.
     * @param AppSettings            $settings Para saber si contabilidad está encendida.
     */
    public function __construct(
        private readonly CashCountRecorder $recorder,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $settings,
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
        return 'Recuento del dinero que hay en una caja de efectivo, escrito como «caja huerta 400,56».';
    }

    public function accepts(TelegramInput $input, User $user): bool
    {
        return $input->kind === TelegramInput::TEXT
            && CashCountMessage::looksLikeOne($input->text)
            && $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
    }

    public function help(User $user): ?string
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD)) {
            return null;
        }

        return 'Arqueo de caja: cuenta el dinero y escríbeme la caja y el importe («caja huerta 400,56»; si no es de hoy, con la fecha detrás: «caja huerta 400,56 30/10»). Te digo si cuadra con el libro.';
    }

    public function commit(TelegramInput $input, User $user): TelegramOutcome
    {
        try {
            $recorded = $this->recorder->record(
                CashCountMessage::parse($input->text, new \DateTimeImmutable('today')),
                $user,
                $input->sentAt,
                $input->text,
                $input->updateId,
            );
        } catch (CashCountRejected $e) {
            throw new TelegramRejected($e->getMessage());
        }

        return new TelegramOutcome((string) $recorded->count->getId(), self::summarize($recorded));
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
