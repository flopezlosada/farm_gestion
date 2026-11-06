<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\AwaitedInvoiceFinder;
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
 * se parte igual ({@see RepeatSplit}). El apunte queda marcado como anotado solo
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
        $awaiting = $this->invoices->findAwaitingPayment();

        $booked = [];
        foreach ($open as $line) {
            $id = (int) $line->getId();
            $provider = $providers[$id] ?? null;
            // Sola, sólo si una única factura espera ese importe. Con dos, elegir es de una
            // persona; y anotarlo sin ninguna dejaría a la buena esperando para siempre.
            $candidates = $line->getAmountCents() < 0 ? self::awaitingFor($line, $awaiting) : [];
            if (\count($candidates) > 1) {
                continue;
            }
            $awaited = $candidates[0] ?? null;
            $suggestion = $suggestions[$id] ?? null;
            $category = $awaited?->getCategory() ?? ($suggestion !== null && $suggestion->times >= self::MIN_TIMES ? $suggestion->category : null);
            if (!self::canBook($category)) {
                continue;
            }
            $entry = BankLineEntryDraft::complete(BankLineEntryDraft::fromLine(new AccountEntry(), $line), $category, $provider, $awaited)
                ->setCreatedBy($by)
                ->markAutoBooked();
            $this->em->persist($entry);
            $line->linkTo($entry);
            $awaited?->paidBy($entry);
            if ($awaited !== null) {
                // Una factura paga un solo movimiento: la siguiente del mismo importe no es ésta.
                $awaiting = array_values(array_filter($awaiting, static fn (ReceivedInvoice $i): bool => $i !== $awaited));
            }
            $booked[] = $entry;
        }
        $this->splitLikeLastTime($booked);

        return $booked;
    }

    /**
     * Las facturas que esperan un pago de ese importe, las más antiguas primero.
     *
     * @param list<ReceivedInvoice> $awaiting Todas las que esperan pago, por antigüedad.
     *
     * @return list<ReceivedInvoice>
     */
    private static function awaitingFor(BankStatementLine $line, array $awaiting): array
    {
        return array_values(array_filter($awaiting, static fn (ReceivedInvoice $i): bool => $i->getTotal() !== null
            && IssuedInvoice::toCents($i->getTotal()) === -$line->getAmountCents()));
    }

    /** Sólo con partida, viva y que no sea de traspasos: un traspaso necesita la otra mitad. */
    private static function canBook(?BudgetCategory $category): bool
    {
        return $category !== null && $category->isActive() && !$category->isTransfer();
    }

    /**
     * Parte los apuntes recién anotados como se partió la última vez el mismo cargo con
     * el mismo total. Una consulta de partidos y otra de partes por cuenta, no por apunte.
     *
     * @param list<AccountEntry> $booked
     */
    private function splitLikeLastTime(array $booked): void
    {
        $byAccount = [];
        foreach ($booked as $entry) {
            $byAccount[spl_object_id($entry->getAccount())][] = $entry;
        }
        foreach ($byAccount as $entries) {
            $account = $entries[0]->getAccount();
            $dates = array_map(static fn (AccountEntry $e): \DateTimeImmutable => \DateTimeImmutable::createFromInterface($e->getDate()), $entries);
            $previous = $this->entries->findSplitBetween($account, min($dates)->modify(self::SPLIT_LOOKBACK), max($dates));
            $parts = $this->entries->findPartsOf($previous);
            foreach ($entries as $entry) {
                $before = array_values(array_filter($previous, static fn (AccountEntry $p): bool => $p->getDate() < $entry->getDate()
                    && $p->getDate() >= \DateTimeImmutable::createFromInterface($entry->getDate())->modify(self::SPLIT_LOOKBACK)));
                $template = RepeatSplit::pick($entry, $before, $parts);
                if ($template === null) {
                    continue;
                }
                foreach (RepeatSplit::apply($entry, $template['parts']) as $part) {
                    $this->em->persist($part->setCreatedBy($entry->getCreatedBy())->markAutoBooked());
                }
            }
        }
    }
}
