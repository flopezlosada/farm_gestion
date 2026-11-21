<?php

namespace App\Service\ConsumerGroup;

use App\Entity\AccountEntry;
use App\Entity\BankPayerAlias;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupTransfer;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\User;
use App\Repository\BankPayerAliasRepository;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupTransferRepository;
use App\Repository\PartnerRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Norma43\BankLineEntryDraft;
use App\Service\Accounting\Norma43\RemittanceCreditBooker;
use App\Service\Accounting\Norma43\TransferPayer;
use App\Service\Accounting\Remittance\PartnerNameMatcher;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Las transferencias con que las familias pagan sus pedidos del grupo de consumo.
 *
 * El banco no trae el concepto que escribe quien paga, sólo el ordenante (cortado) y el
 * importe. Así que un ingreso paga un pedido cuando:
 *  - debe justo ese importe, al céntimo, y
 *  - el ordenante se parece a alguien de la familia
 *    ({@see PartnerNameMatcher::matchesBankPayer()}) o ya pagó antes por ella
 *    ({@see BankPayerAlias}).
 *
 * Con un solo pedido así, el cruce lo asigna solo al subir el extracto. Con varios o
 * ninguno, el movimiento queda para elegir a mano, con los pedidos más probables
 * arriba. Al elegir a mano se aprende el ordenante.
 *
 * Sólo miran ingresos sin apunte o anotados en la partida del grupo de consumo, y
 * desde el día del primer pedido de la app: una cuota no paga un pedido, ni lo pagan
 * los ingresos de antes de que hubiera pedidos.
 *
 * No guarda: lo hace quien llama.
 */
class ConsumerGroupTransfers
{
    /** Cuántas sugerencias se llevan a la pantalla por movimiento; el resto se busca en el buscador de pedidos. */
    private const MAX_SHOWN = 60;

    public function __construct(
        private readonly ConsumerGroupTransferRepository $transfers,
        private readonly ConsumerGroupOrderRepository $orders,
        private readonly BankPayerAliasRepository $aliases,
        private readonly PartnerRepository $partners,
        private readonly RemittanceCreditBooker $creditBooker,
        private readonly ClosedPeriods $closed,
        private readonly AppSettings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Asigna los ingresos sin decidir que pagan sin duda un pedido. Sin el grupo de
     * consumo en marcha no hace nada.
     *
     * @param User|null $by quien sube o vuelve a cruzar el extracto
     *
     * @return int cuántos movimientos ha asignado
     */
    public function matchAll(?User $by): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $lines = $this->undecided(false);
        if ($lines === []) {
            return 0;
        }
        $payable = $this->orders->findPayableByTransfer();
        $aliases = $this->aliasesFor($lines);

        $assigned = 0;
        foreach ($lines as $line) {
            $sure = array_values(array_filter(
                self::suggestions($line, $payable, self::aliasFamily($line, $aliases)),
                static fn (array $s): bool => $s['sure'],
            ));
            if (\count($sure) !== 1) {
                continue;
            }
            $order = $sure[0]['order'];
            $this->em->persist((new ConsumerGroupTransfer($line))->assign([$order], true));
            $this->bookIfMissing($line, $by);
            $payable = array_values(array_filter($payable, static fn (ConsumerGroupOrder $o): bool => $o !== $order));
            ++$assigned;
        }

        return $assigned;
    }

    /**
     * Lo que falta por elegir a mano, con los pedidos que pueden ser, los más probables
     * primero, y todos los pedidos por pagar para elegir otro. Sólo los movimientos que
     * pueden ser el pago de un pedido: deben justo uno, o los ordena una socia
     * ({@see isFromMember()}).
     *
     * @return array{lines: list<array{line: BankStatementLine, payer: string|null, relevant: bool, suggestions: list<array{order: ConsumerGroupOrder, sure: bool, exact: bool, named: bool, reasons: list<string>}>, groups: list<array{exact: bool, round: ConsumerGroupRound, items: list<array{order: ConsumerGroupOrder, sure: bool, exact: bool, named: bool, reasons: list<string>}>}>, hidden: int}>, payable: list<ConsumerGroupOrder>}
     */
    public function pending(): array
    {
        $lines = $this->undecided(true);
        $payable = $lines === [] ? [] : $this->orders->findPayableByTransfer();
        $aliases = $this->aliasesFor($lines);

        $members = $lines === [] ? [] : $this->partners->findMembers();

        $rows = array_map(static function (BankStatementLine $line) use ($payable, $aliases, $members): array {
            $aliasFamily = self::aliasFamily($line, $aliases);
            $suggestions = self::suggestions($line, $payable, $aliasFamily);

            return [
                'line' => $line,
                'payer' => $line->getTransferPayer(),
                'suggestions' => $suggestions,
                'groups' => self::groupSuggestions(\array_slice($suggestions, 0, self::MAX_SHOWN)),
                'hidden' => max(0, \count($suggestions) - self::MAX_SHOWN),
                'relevant' => $suggestions !== [] || self::isFromMember($line, $members, $aliasFamily),
            ];
        }, $lines);

        return [
            'lines' => array_values(array_filter($rows, static fn (array $row): bool => $row['relevant'])),
            'payable' => $payable,
        ];
    }

    /**
     * Cuántos movimientos faltan por elegir a mano, para el aviso de la portada.
     */
    public function countPending(): int
    {
        $from = $this->enabled() ? $this->orders->firstCreatedOn() : null;
        $withPayer = $from === null ? [] : array_filter(
            $this->transfers->findUndecidedDetails($from, $this->category(), true),
            static fn (?string $details): bool => TransferPayer::fromDetails((string) $details) !== null,
        );

        // Sin candidatos no hace falta más. Con ellos se cuenta lo que la pantalla
        // enseña, que además descarta los que no pueden ser el pago de un pedido.
        return $withPayer === [] ? 0 : \count($this->pending()['lines']);
    }

    /**
     * Una persona dice qué pedidos paga un movimiento. Aprende el ordenante para la
     * próxima vez y, si el movimiento aún no está en el libro, lo anota en la partida
     * del grupo de consumo.
     *
     * @param BankStatementLine                  $line   un ingreso por decidir
     * @param non-empty-list<ConsumerGroupOrder> $orders los pedidos que paga, de una o varias familias
     * @param User|null                          $by     quien lo decide
     *
     * @throws \InvalidArgumentException si ya está asignado o algún pedido no se puede pagar
     */
    public function assign(BankStatementLine $line, array $orders, ?User $by): ConsumerGroupTransfer
    {
        if ($line->getAmountCents() <= 0) {
            throw new \InvalidArgumentException('Sólo un ingreso puede pagar un pedido.');
        }
        $entry = $line->getEntry();
        if ($entry !== null && $entry->getCategory() !== $this->category()) {
            throw new \InvalidArgumentException('Este movimiento ya está anotado en otra partida: no es el pago de un pedido.');
        }
        $transfer = $this->transfers->findOneBy(['line' => $line]) ?? new ConsumerGroupTransfer($line);
        if ($transfer->getOrders() !== []) {
            throw new \InvalidArgumentException('Este movimiento ya paga otros pedidos: suéltalo antes.');
        }
        $this->em->persist($transfer->assign($orders, false));
        if (self::isUnambiguousPayment($line, $orders)) {
            $this->learn($line, $orders[0]->getPartner());
        }
        $this->bookIfMissing($line, $by);

        return $transfer;
    }

    /**
     * Deshace una asignación: los pedidos vuelven a pendientes y el movimiento a la
     * lista para elegir. Si el ordenante se había aprendido para esa familia, se
     * olvida, para que no vuelva a casar solo con ella. El apunte no se toca.
     */
    public function release(ConsumerGroupTransfer $transfer): void
    {
        $families = array_map(static fn (ConsumerGroupOrder $o): Partner => $o->getPartner()->getFamilyHead(), $transfer->getOrders());
        // Un solo movimiento: como mucho un ordenante aprendido.
        $alias = array_values($this->aliasesFor([$transfer->getLine()]))[0] ?? null;
        if ($alias !== null && \in_array($alias->getPartner(), $families, true)) {
            $this->em->remove($alias);
        }
        $transfer->release();
    }

    /**
     * «No es el pago de ningún pedido»: el movimiento deja de pedir que se elija.
     */
    public function dismiss(BankStatementLine $line): ConsumerGroupTransfer
    {
        $transfer = $this->transfers->findOneBy(['line' => $line]) ?? new ConsumerGroupTransfer($line);
        if ($transfer->getOrders() !== []) {
            $this->release($transfer);
        }
        $this->em->persist($transfer->dismiss());

        return $transfer;
    }

    /**
     * Si el movimiento apartado sigue anotado, sin que nadie lo corrigiera, en la partida
     * del grupo de consumo. Apartarlo no toca el libro: ese apunte (anotado solo al
     * asignarlo, o por una regla) queda en una partida que ya no le corresponde, y hay
     * que avisar de que se corrija desde el extracto.
     */
    public function staysBookedAsConsumerGroup(BankStatementLine $line): bool
    {
        $entry = $line->getEntry();

        return $entry !== null && $entry->isAutoBooked() && $entry->getCategory() !== null && $entry->getCategory() === $this->category();
    }

    /**
     * Los pedidos que puede pagar un movimiento, los más probables primero: los que
     * deben ese importe y son de quien paga (seguros), los de quien paga con otro
     * importe, y los del importe de otras familias. Puro.
     *
     * @param list<ConsumerGroupOrder> $payable     los pedidos por pagar
     * @param Partner|null             $aliasFamily la familia que ya pagó antes desde ese ordenante
     *
     * @return list<array{order: ConsumerGroupOrder, sure: bool, exact: bool, named: bool, reasons: list<string>}>
     */
    public static function suggestions(BankStatementLine $line, array $payable, ?Partner $aliasFamily): array
    {
        $payer = $line->getTransferPayer();
        $found = [];
        foreach ($payable as $order) {
            $family = $order->getPartner()->getFamilyMembers();
            $amount = (int) round($order->getTotal() * 100) === $line->getAmountCents();
            $byAlias = $aliasFamily !== null && $order->getPartner()->getFamilyHead() === $aliasFamily;
            $byName = !$byAlias && $payer !== null && array_filter(
                $family,
                static fn (Partner $p): bool => PartnerNameMatcher::matchesBankPayer($payer, $p),
            ) !== [];
            if (!$amount && !$byAlias && !$byName) {
                continue;
            }
            $reasons = array_values(array_filter([
                $amount ? 'Debe justo este importe' : sprintf('Debe %s €, no este importe', number_format($order->getTotal(), 2, ',', '.')),
                $byAlias ? sprintf('«%s» ya pagó antes por esta familia', $payer) : null,
                $byName ? sprintf('«%s» se parece a alguien de la familia', $payer) : null,
            ]));
            $found[] = [
                'order' => $order,
                'sure' => $amount && ($byAlias || $byName),
                'exact' => $amount,
                'named' => $byAlias || $byName,
                'reasons' => $reasons,
                'rank' => ($amount ? 1 : 0) + ($byAlias || $byName ? 2 : 0),
            ];
        }
        // usort es estable: a igual rango, en el orden de entrega.
        usort($found, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return array_map(static fn (array $s): array => ['order' => $s['order'], 'sure' => $s['sure'], 'exact' => $s['exact'], 'named' => $s['named'], 'reasons' => $s['reasons']], $found);
    }

    /**
     * Si quien ordena la transferencia es una socia: la familia ya pagó antes desde ese
     * ordenante, o su nombre es el de alguna ficha de socia. Es lo que separa un pago al
     * grupo de consumo de un ingreso que no tiene que ver (una empresa, una subvención):
     * un movimiento sólo se pide elegir si debe justo un pedido o lo paga una socia. Puro.
     *
     * @param list<Partner> $members     las socias
     * @param Partner|null  $aliasFamily la familia que ya pagó antes desde ese ordenante
     */
    public static function isFromMember(BankStatementLine $line, array $members, ?Partner $aliasFamily): bool
    {
        $payer = $line->getTransferPayer();

        return $payer !== null && ($aliasFamily !== null || array_filter(
            $members,
            static fn (Partner $p): bool => PartnerNameMatcher::matchesBankPayer($payer, $p),
        ) !== []);
    }

    /**
     * Agrupa las sugerencias para pintarlas: por si deben justo el importe y por ronda,
     * en el orden en que aparece cada grupo. Una ronda con 30 pedidos del mismo importe
     * se lee una vez, no 30. Puro.
     *
     * @param list<array{order: ConsumerGroupOrder, sure: bool, exact: bool, named: bool, reasons: list<string>}> $suggestions
     *
     * @return list<array{exact: bool, round: ConsumerGroupRound, items: list<array{order: ConsumerGroupOrder, sure: bool, exact: bool, named: bool, reasons: list<string>}>}>
     */
    public static function groupSuggestions(array $suggestions): array
    {
        $groups = [];
        foreach ($suggestions as $suggestion) {
            $round = $suggestion['order']->getRound();
            $key = ($suggestion['exact'] ? 'e' : 'n') . spl_object_id($round);
            $groups[$key] ??= ['exact' => $suggestion['exact'], 'round' => $round, 'items' => []];
            $groups[$key]['items'][] = $suggestion;
        }

        return array_values($groups);
    }

    /**
     * Los ingresos sin decidir que son transferencias de alguien, desde el primer
     * pedido. Lo que no trae ordenante (un traspaso, el abono de una remesa) no es el
     * pago de un pedido.
     *
     * @param bool $withReleased si entran los que una persona soltó
     *
     * @return list<BankStatementLine>
     */
    private function undecided(bool $withReleased): array
    {
        $from = $this->orders->firstCreatedOn();

        return $from === null ? [] : array_values(array_filter(
            $this->transfers->findUndecidedLines($from, $this->category(), $withReleased),
            static fn (BankStatementLine $l): bool => $l->getTransferPayer() !== null,
        ));
    }

    /** Si el grupo de consumo está en marcha: sin él, ni se cruza ni se avisa. */
    private function enabled(): bool
    {
        return $this->settings->getBool(AppSettings::FEATURE_GRUPO_CONSUMO);
    }

    /** La partida del grupo de consumo, la misma que el abono de su remesa. */
    private function category(): ?BudgetCategory
    {
        return $this->creditBooker->categoryForKind(Remittance::KIND_CONSUMER_GROUP);
    }

    /**
     * Los ordenantes aprendidos de unos movimientos, en una consulta.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return array<string, BankPayerAlias>
     */
    private function aliasesFor(array $lines): array
    {
        return $this->aliases->byKey(array_values(array_filter(array_map(
            static fn (BankStatementLine $l): ?string => ($payer = $l->getTransferPayer()) !== null ? PartnerNameMatcher::normalize($payer) : null,
            $lines,
        ))));
    }

    /**
     * La familia aprendida para el ordenante del movimiento, si hay.
     *
     * @param array<string, BankPayerAlias> $aliases
     */
    private static function aliasFamily(BankStatementLine $line, array $aliases): ?Partner
    {
        $payer = $line->getTransferPayer();

        return $payer !== null ? ($aliases[PartnerNameMatcher::normalize($payer)] ?? null)?->getPartner() : null;
    }

    /**
     * Si la asignación dice con certeza quién es el ordenante: los pedidos son de una sola
     * familia y suman justo el importe. Una asignación que no cuadra puede ser un pago
     * parcial o un error, y de ella no se aprende nada.
     *
     * @param non-empty-list<ConsumerGroupOrder> $orders
     */
    private static function isUnambiguousPayment(BankStatementLine $line, array $orders): bool
    {
        $families = array_unique(array_map(static fn (ConsumerGroupOrder $o): ?int => $o->getPartner()->getFamilyHead()->getId(), $orders));
        $due = array_sum(array_map(static fn (ConsumerGroupOrder $o): int => (int) round($o->getTotal() * 100), $orders));

        return \count($families) === 1 && $due === $line->getAmountCents();
    }

    /** Liga el ordenante del movimiento a esa familia (o la cambia, si era de otra). */
    private function learn(BankStatementLine $line, Partner $partner): void
    {
        $payer = $line->getTransferPayer();
        if ($payer === null) {
            return;
        }
        $key = PartnerNameMatcher::normalize($payer);
        $alias = $this->aliases->byKey([$key])[$key] ?? null;
        $this->em->persist($alias?->pointTo($partner) ?? new BankPayerAlias($key, $partner));
    }

    /**
     * Si el movimiento no está en el libro, lo anota en la partida del grupo de consumo,
     * como anotado solo (se revisa como los demás). No si el trimestre ya se entregó a
     * la gestoría o la partida no existe: queda para anotarlo a mano desde el extracto.
     */
    private function bookIfMissing(BankStatementLine $line, ?User $by): void
    {
        $category = $this->category();
        if ($line->getEntry() !== null || $category === null || $this->closed->closingDeliveryFor($line->getOperationDate()) !== null) {
            return;
        }
        $entry = BankLineEntryDraft::fromLine(new AccountEntry(), $line)
            ->setConcept(sprintf('Grupo de consumo: transferencia de %s', $line->getTransferPayer()))
            ->setCategory($category)
            ->setCreatedBy($by)
            ->markAutoBooked();
        $this->em->persist($entry);
        $line->linkTo($entry);
    }
}
