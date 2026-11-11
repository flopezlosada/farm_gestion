<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\FinancialAccount;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Entity\RemittanceReturn;
use App\Entity\ReturnOutcome;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\RemittanceReturnRepository;
use App\Service\Accounting\Remittance\RemittanceCredits;

/**
 * El pago de los pedidos del grupo de consumo que van en un recibo de una remesa
 * ya enviada, según lo que diga el banco.
 *
 * ES EL ÚNICO QUE LO ESCRIBE. Enviar la remesa no cobra nada: el pedido queda «en
 * el recibo» hasta que su abono entra en el libro. Si el banco devuelve el recibo,
 * el pedido queda devuelto hasta que la comisión cierra la devolución
 * ({@see ReturnOutcome}): pagado aparte lo da por pagado; volver a cobrar lo lleva
 * al recibo nuevo, que pasa otra vez por lo mismo.
 *
 * NO GUARDA ESTADO PROPIO: cada pasada recalcula todos esos pedidos desde la
 * remesa, sus abonos y sus devoluciones. Así da igual el orden en que lleguen las
 * cosas (la devolución suele entrar días después del abono) y deshacer una
 * devolución o reabrirla se corrige sola en la pasada siguiente.
 *
 * Se llama al cruzar un extracto con el libro y tras cada cambio en una
 * devolución. No guarda: lo hace quien llama.
 */
class ConsumerGroupReceiptSync
{
    /** Vueltas a cobrar seguidas que se siguen como mucho: corta un ciclo imposible. */
    private const MAX_RETRIES = 12;

    public function __construct(
        private readonly ConsumerGroupOrderRepository $orders,
        private readonly RemittanceReturnRepository $returns,
        private readonly RemittanceCredits $credits,
    ) {
    }

    /**
     * Recalcula el pago de todos los pedidos que van en un recibo enviado.
     *
     * @return int a cuántos les ha cambiado algo
     */
    public function syncAll(): int
    {
        $orders = $this->orders->findInSentReceipts();
        if ($orders === []) {
            return 0;
        }

        $returnsByLine = [];
        foreach ($this->returns->findByKind(Remittance::KIND_CONSUMER_GROUP) as $return) {
            $returnsByLine[$return->getLine()->getId()] = $return;
        }

        /** @var array<int, \DateTimeInterface|null> $creditedOn día del abono por id de remesa, buscado una vez */
        $creditedOn = [];
        $credited = function (Remittance $remittance) use (&$creditedOn): ?\DateTimeInterface {
            return \array_key_exists($remittance->getId(), $creditedOn)
                ? $creditedOn[$remittance->getId()]
                : $creditedOn[$remittance->getId()] = $this->creditDate($remittance);
        };

        $changed = 0;
        foreach ($orders as $order) {
            $outcome = self::outcome($order->getRemittanceLine(), $returnsByLine, $credited);
            if ($order->getPaidVia() === $outcome['via']
                && $order->isReceiptReturned() === $outcome['returned']
                && self::sameDay($order->getPaidAt(), $outcome['at'])) {
                continue;
            }
            $order->settleReceipt($outcome['via'], $outcome['at'], $outcome['returned']);
            ++$changed;
        }

        return $changed;
    }

    /**
     * Cómo queda un pedido según su recibo. Sigue la cadena de vueltas a cobrar:
     * si el recibo se devolvió y se volvió a pasar, manda el recibo nuevo. Puro.
     *
     * @param RemittanceLine                                    $line          el recibo en que va el pedido
     * @param array<int, RemittanceReturn>                      $returnsByLine devoluciones por id de recibo
     * @param callable(Remittance): (\DateTimeInterface|null)   $creditedOn    día en que entró el abono de una remesa, o null
     *
     * @return array{via: ConsumerGroupPaymentMethod|null, at: \DateTimeInterface|null, returned: bool}
     */
    public static function outcome(RemittanceLine $line, array $returnsByLine, callable $creditedOn): array
    {
        $unpaid = ['via' => null, 'at' => null, 'returned' => false];
        for ($i = 0; $i < self::MAX_RETRIES; ++$i) {
            $remittance = $line->getRemittance();
            if ($remittance === null || !$remittance->isSent()) {
                // Un recibo nuevo aún en el borrador: vuelve a «ir en el recibo».
                return $unpaid;
            }
            $return = $returnsByLine[$line->getId()] ?? null;
            if ($return === null) {
                $at = $creditedOn($remittance);

                return $at !== null ? ['via' => ConsumerGroupPaymentMethod::Receipt, 'at' => $at, 'returned' => false] : $unpaid;
            }
            $paidWith = $return->getPaidWith();
            if ($return->getOutcome() === ReturnOutcome::PaidApart && $paidWith !== null && $paidWith->getDate() !== null) {
                return ['via' => self::methodOf($paidWith->getAccount()), 'at' => $paidWith->getDate(), 'returned' => false];
            }
            $retry = $return->getRechargedIn();
            if ($return->getOutcome() !== ReturnOutcome::Recharge || $retry === null) {
                return ['via' => null, 'at' => null, 'returned' => true];
            }
            $line = $retry;
        }

        return ['via' => null, 'at' => null, 'returned' => true];
    }

    /**
     * El día del primer lote del abono de la remesa, si ya está en el libro y el
     * libro pasa del periodo en que puede entrar ({@see RemittanceCredits::checkFor()}).
     */
    private function creditDate(Remittance $remittance): ?\DateTimeInterface
    {
        $dates = array_filter(array_map(
            static fn ($lot) => $lot->getDate(),
            $this->credits->checkFor($remittance)['lots'] ?? [],
        ));

        return $dates === [] ? null : min($dates);
    }

    /** Pagado aparte en una caja es metálico; en cualquier otra cuenta, transferencia. */
    private static function methodOf(?FinancialAccount $account): ConsumerGroupPaymentMethod
    {
        return $account?->getKind() === FinancialAccount::KIND_CASH
            ? ConsumerGroupPaymentMethod::Cash
            : ConsumerGroupPaymentMethod::Transfer;
    }

    /** Si dos fechas (o su ausencia) son el mismo día. */
    private static function sameDay(?\DateTimeInterface $a, ?\DateTimeInterface $b): bool
    {
        return $a?->format('Y-m-d') === $b?->format('Y-m-d');
    }
}
