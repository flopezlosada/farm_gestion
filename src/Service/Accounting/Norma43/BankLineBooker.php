<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\IssuedInvoice;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\AwaitedInvoiceFinder;
use App\Service\Accounting\Invoice\AwaitingInvoices;
use App\Service\Accounting\Split\RepeatSplit;
use App\Service\Accounting\Suggestion\CategorySuggester;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Anota sola, al subir un extracto, los movimientos del banco que el libro aún no
 * tiene y de los que no hay duda. El banco ya dice la cuenta, la fecha, el importe y
 * el concepto; lo único que falta es la partida, y se pone sola sólo cuando se sabe:
 *
 *  - una factura aceptada, y sólo una, espera ese importe ({@see AwaitedInvoiceFinder}):
 *    su partida, su proveedor y su número, y queda enganchada a ella;
 *  - o las últimas veces que el banco dijo lo mismo en esa cuenta llevaron todas la
 *    misma partida ({@see CategorySuggester}), y fueron al menos {@see MIN_TIMES}:
 *    una sola vez puede ser casualidad.
 *
 * Si la última vez el mismo cargo se partió con el mismo total (el del Ayuntamiento),
 * se parte igual ({@see RepeatSplit}), y si una factura del proveedor que nombra el
 * banco espera justo uno de los trozos, queda enganchada a ése. Si una factura así
 * espera MENOS que el cargo (el alquiler dentro de alquiler + suministros), se parte
 * en lo de la factura, enganchado a ella, y el resto, con la partida y el concepto de
 * lo que se separó la última vez (o, si nunca se partió, la partida de siempre).
 * El apunte queda marcado como anotado solo
 * ({@see AccountEntry::isAutoBooked()}) y se corrige como cualquier otro hasta que el
 * trimestre se entrega a la gestoría.
 *
 * Lo que no se anota solo se queda en el extracto con lo que se propone: los traspasos
 * (necesitan la otra mitad), lo que no tiene partida clara y lo de un trimestre ya
 * entregado, que no se toca. Sin IA: los conceptos del banco llevan nombres de socixs y
 * no salen de la app.
 */
class BankLineBooker
{
    /** Cuántas veces anteriores tienen que coincidir para anotar solo. */
    public const MIN_TIMES = 2;

    /** Hasta cuánto atrás se busca el último cargo partido igual, como en {@see RepeatSplit}. */
    private const SPLIT_LOOKBACK = '-120 days';

    public function __construct(
        private readonly CategorySuggester $suggester,
        private readonly ReceivedInvoiceRepository $invoices,
        private readonly AccountEntryRepository $entries,
        private readonly ClosedPeriods $closed,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Anota y liga los movimientos que no dejan duda. No guarda: lo hace quien llama,
     * con lo demás. Las consultas son por lote, no por movimiento.
     *
     * @param list<BankStatementLine> $lines Movimientos sin apunte.
     * @param User|null               $by    Quien sube o vuelve a cruzar el extracto.
     *
     * @return list<AccountEntry> Los apuntes anotados, sin sus partes.
     */
    public function bookSafe(array $lines, ?User $by): array
    {
        $open = array_values(array_filter($lines, fn (BankStatementLine $l): bool => $l->getEntry() === null
            && $this->closed->closingDeliveryFor($l->getOperationDate()) === null));
        if ($open === []) {
            return [];
        }
        $suggestions = $this->suggester->forLines($open);
        $providers = $this->suggester->providersFor($open);
        $awaiting = new AwaitingInvoices($this->invoices->findAwaitingPayment());
        $splits = $this->splitsBefore($open);

        $booked = [];
        foreach ($open as $line) {
            $id = (int) $line->getId();
            $provider = $providers[$id] ?? null;
            $suggestion = $suggestions[$id] ?? null;
            $category = $suggestion !== null && $suggestion->times >= self::MIN_TIMES ? $suggestion->category : null;
            [$previous, $parts] = self::splitsFor($line, $splits);
            // Sola, sólo si una única factura espera ese importe. Con dos, elegir es de una
            // persona; y anotarlo sin ninguna dejaría a la buena esperando para siempre.
            $candidates = $line->getAmountCents() < 0 ? $awaiting->paidBy($line->getAmountCents()) : [];
            if (\count($candidates) > 1) {
                continue;
            }
            // Si se parte como la última vez, manda eso: la factura va luego a su trozo.
            // Partir por una factura más pequeña, sólo si este cargo ya se partió alguna
            // vez: sin ese precedente, un cargo de siempre se partiría por cualquier otra
            // factura del mismo proveedor. Entonces se anota entero y se parte a mano.
            $draft = BankLineEntryDraft::fromLine(new AccountEntry(), $line);
            $lastPart = $candidates === [] && RepeatSplit::pick($draft, $previous, $parts) === null
                ? RepeatSplit::lastPartOf($draft, $previous, $parts)
                : null;
            $partial = $lastPart !== null ? self::partialFor($line, $provider, $awaiting) : null;
            $entry = $partial !== null && $lastPart !== null
                ? $this->bookWithInvoicePart($line, $partial, $lastPart, $provider, $by, $awaiting)
                : $this->bookWhole($line, $candidates[0] ?? null, $category, $provider, $previous, $parts, $by, $awaiting);
            if ($entry !== null) {
                $booked[] = $entry;
            }
        }

        return $booked;
    }

    /**
     * Anota el movimiento entero con su partida (la de la factura que lo espera, o la de
     * siempre) y, si la última vez se partió igual, lo parte igual y engancha a su trozo
     * la factura que espera justo ése.
     *
     * @param list<AccountEntry> $previous Partidos de antes de la misma cuenta, más recientes primero.
     * @param list<AccountEntry> $parts    Sus partes.
     */
    private function bookWhole(BankStatementLine $line, ?ReceivedInvoice $awaited, ?BudgetCategory $category, ?Provider $provider, array $previous, array $parts, ?User $by, AwaitingInvoices $awaiting): ?AccountEntry
    {
        $category = $awaited?->getCategory() ?? $category;
        if (!self::canBook($category)) {
            return null;
        }
        $entry = $this->book(BankLineEntryDraft::complete(BankLineEntryDraft::fromLine(new AccountEntry(), $line), $category, $provider, $awaited), $line, $by);
        if ($awaited !== null) {
            self::pay($awaited, $entry, $awaiting);
        }
        $template = RepeatSplit::pick($entry, $previous, $parts);
        if ($template === null) {
            return $entry;
        }
        $pieces = [$entry];
        foreach (RepeatSplit::apply($entry, $template['parts']) as $part) {
            $this->em->persist($part->setCreatedBy($by)->markAutoBooked());
            $pieces[] = $part;
        }
        if ($awaited === null) {
            self::payPiece($line, $provider, $pieces, $awaiting);
        }

        return $entry;
    }

    /**
     * Anota el cargo partido en lo de la factura, enganchado a ella, y el resto, con la
     * partida y el concepto de lo que se separó la última vez. Sin partida viva para el
     * resto no se anota: lo decide una persona.
     *
     * @param AccountEntry $lastPart Lo que se separó la última vez que se partió este cargo.
     */
    private function bookWithInvoicePart(BankStatementLine $line, ReceivedInvoice $invoice, AccountEntry $lastPart, ?Provider $provider, ?User $by, AwaitingInvoices $awaiting): ?AccountEntry
    {
        $draft = BankLineEntryDraft::fromLine(new AccountEntry(), $line);
        $restCategory = $lastPart->getCategory();
        if (!self::canBook($restCategory) || !self::canBook($invoice->getCategory() ?? $restCategory)) {
            return null;
        }
        $restConcept = $lastPart->getConcept();
        $entry = BankLineEntryDraft::complete($draft, $restCategory, $provider, $invoice);
        $rest = $entry->splitOff(IssuedInvoice::fromCents(-$line->getAmountCents() - IssuedInvoice::toCents((string) $invoice->getTotal())), $restCategory, $restConcept);
        $this->book($entry, $line, $by);
        $this->em->persist($rest->setCreatedBy($by)->markAutoBooked());
        self::pay($invoice, $entry, $awaiting);

        return $entry;
    }

    /** Persiste el apunte marcado como anotado solo y lo liga al movimiento. */
    private function book(AccountEntry $entry, BankStatementLine $line, ?User $by): AccountEntry
    {
        $this->em->persist($entry->setCreatedBy($by)->markAutoBooked());
        $line->linkTo($entry);

        return $entry;
    }

    /** Engancha la factura a su pago. Una factura paga un solo movimiento: deja de esperar. */
    private static function pay(ReceivedInvoice $invoice, AccountEntry $entry, AwaitingInvoices $awaiting): void
    {
        $invoice->paidBy($entry);
        $awaiting->remove($invoice);
    }

    /**
     * De un cargo ya partido, engancha la factura que espera justo uno de sus trozos: la
     * única del proveedor que nombra el banco con ese importe, y sólo si un único trozo
     * lo tiene.
     *
     * @param non-empty-list<AccountEntry> $pieces El apunte y sus partes.
     */
    private static function payPiece(BankStatementLine $line, ?Provider $provider, array $pieces, AwaitingInvoices $awaiting): void
    {
        $matches = [];
        foreach ($pieces as $piece) {
            foreach ($awaiting->paidBy(IssuedInvoice::toCents($piece->getAmount())) as $invoice) {
                if (AwaitedInvoiceFinder::names($line, $provider, $invoice)) {
                    $matches[] = [$invoice, $piece];
                }
            }
        }
        if (\count($matches) === 1) {
            self::pay($matches[0][0], $matches[0][1], $awaiting);
        }
    }

    /**
     * La única factura del proveedor que nombra el banco que espera menos que el cargo:
     * una parte de él. Null si no hay ninguna o hay varias.
     */
    private static function partialFor(BankStatementLine $line, ?Provider $provider, AwaitingInvoices $awaiting): ?ReceivedInvoice
    {
        if ($line->getAmountCents() >= 0) {
            return null;
        }
        $found = array_values(array_filter($awaiting->partOf($line->getAmountCents()), static fn (ReceivedInvoice $i): bool => AwaitedInvoiceFinder::names($line, $provider, $i)));

        return \count($found) === 1 ? $found[0] : null;
    }

    /** Sólo con partida, viva y que no sea de traspasos: un traspaso necesita la otra mitad. */
    private static function canBook(?BudgetCategory $category): bool
    {
        return $category !== null && $category->isActive() && !$category->isTransfer();
    }

    /**
     * Los apuntes partidos de antes de estos movimientos, con sus partes, por cuenta:
     * una consulta de partidos y otra de partes por cuenta, no por movimiento.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return array<int, array{0: list<AccountEntry>, 1: list<AccountEntry>}> Por spl_object_id de la cuenta.
     */
    private function splitsBefore(array $lines): array
    {
        $byAccount = [];
        foreach ($lines as $line) {
            $byAccount[spl_object_id($line->getAccount())][] = $line;
        }

        return array_map(function (array $accountLines): array {
            $dates = array_map(static fn (BankStatementLine $l): \DateTimeImmutable => $l->getOperationDate(), $accountLines);
            $previous = $this->entries->findSplitBetween($accountLines[0]->getAccount(), min($dates)->modify(self::SPLIT_LOOKBACK), max($dates));

            return [$previous, $this->entries->findPartsOf($previous)];
        }, $byAccount);
    }

    /**
     * De los partidos de su cuenta, los de antes del movimiento y dentro de
     * {@see SPLIT_LOOKBACK}, y sus partes.
     *
     * @param array<int, array{0: list<AccountEntry>, 1: list<AccountEntry>}> $splits De {@see splitsBefore()}.
     *
     * @return array{0: list<AccountEntry>, 1: list<AccountEntry>}
     */
    private static function splitsFor(BankStatementLine $line, array $splits): array
    {
        [$previous, $parts] = $splits[spl_object_id($line->getAccount())] ?? [[], []];
        $day = $line->getOperationDate();
        $before = array_values(array_filter($previous, static fn (AccountEntry $p): bool => $p->getDate() < $day
            && $p->getDate() >= $day->modify(self::SPLIT_LOOKBACK)));

        return [$before, $parts];
    }
}
