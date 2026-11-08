<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\FinancialAccountRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\ConceptKey;
use App\Service\Accounting\TransferRecorder;

/**
 * Anota sola la liquidación de una tarjeta: el cargo del banco con el concepto que
 * dice la ficha de la tarjeta ({@see FinancialAccount::getSettlementConcept()}) es
 * un traspaso del banco a la tarjeta, y se anota con sus dos mitades
 * ({@see TransferRecorder}). Las compras se anotan en la cuenta de la tarjeta, no aquí.
 *
 * No se anota si ese día el banco ya tiene gastos sueltos sin movimiento: es la manera
 * antigua (las compras apuntadas una a una en el banco el día de la liquidación), y un
 * traspaso encima las contaría dos veces. Tampoco en un trimestre entregado, ni si el
 * concepto es el de dos tarjetas.
 */
class CardSettlementBooker
{
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly AccountEntryRepository $entries,
        private readonly ClosedPeriods $closed,
        private readonly TransferRecorder $transfers,
    ) {
    }

    /**
     * Anota como traspaso a su tarjeta los cargos que la liquidan y los liga. No guarda:
     * lo hace quien llama. Dos consultas por lote, no por movimiento.
     *
     * @param list<BankStatementLine> $lines Movimientos sin apunte.
     * @param User|null               $by    Quien sube o vuelve a cruzar el extracto.
     *
     * @return list<AccountEntry> La mitad de cada traspaso que sale del banco.
     */
    public function bookSafe(array $lines, ?User $by): array
    {
        $cards = $this->cardsByConcept();
        $settlements = array_values(array_filter($lines, fn (BankStatementLine $l): bool => $l->getEntry() === null
            && $l->getAmountCents() < 0
            && $l->getAccount()->isBank()
            && \count($cards[ConceptKey::of($l->getSuggestedConcept())] ?? []) === 1
            && $this->closed->closingDeliveryFor($l->getOperationDate()) === null));
        $busy = $this->daysWithLooseExpenses($settlements);

        $booked = [];
        foreach ($settlements as $line) {
            if (isset($busy[self::dayKey($line->getAccount(), $line->getOperationDate())])) {
                continue;
            }
            $out = $this->transfers->record(
                $line->getAccount(),
                $cards[ConceptKey::of($line->getSuggestedConcept())][0],
                IssuedInvoice::fromCents(-$line->getAmountCents()),
                $line->getOperationDate(),
                $line->getSuggestedConcept(),
                $by,
            );
            $out->markAutoBooked();
            $out->getTransferPeer()?->markAutoBooked();
            $line->linkTo($out);
            $booked[] = $out;
        }

        return $booked;
    }

    /**
     * Las tarjetas activas que dicen con qué concepto las cobra el banco, por la clave
     * de ese concepto ({@see ConceptKey}).
     *
     * @return array<string, list<FinancialAccount>>
     */
    private function cardsByConcept(): array
    {
        $cards = [];
        foreach ($this->accounts->findActive() as $account) {
            $key = ConceptKey::of($account->getSettlementConcept() ?? '');
            if ($key !== '') {
                $cards[$key][] = $account;
            }
        }

        return $cards;
    }

    /**
     * Los días y cuentas de estos cargos en que el banco ya tiene gastos sueltos sin
     * movimiento (ni traspasos ni ingresos), en una consulta.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return array<string, true> Por {@see dayKey()}.
     */
    private function daysWithLooseExpenses(array $lines): array
    {
        $accounts = [];
        foreach ($lines as $line) {
            $accounts[spl_object_id($line->getAccount())] = $line->getAccount();
        }
        $busy = [];
        $loose = $this->entries->findWithoutBankLineOn(array_values($accounts), array_map(static fn (BankStatementLine $l): \DateTimeImmutable => $l->getOperationDate(), $lines));
        foreach ($loose as $entry) {
            if (!$entry->isTransfer() && !$entry->isIncoming() && $entry->getAccount() !== null && $entry->getDate() !== null) {
                $busy[self::dayKey($entry->getAccount(), $entry->getDate())] = true;
            }
        }

        return $busy;
    }

    /** Cuenta y día. */
    private static function dayKey(FinancialAccount $account, \DateTimeInterface $day): string
    {
        return $account->getId().'|'.$day->format('Y-m-d');
    }
}
