<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\BankStatementLine;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupTransfer;
use App\Entity\FinancialAccount;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Norma43\Norma43Movement;
use App\Service\ConsumerGroup\ConsumerGroupTransfers;
use PHPUnit\Framework\TestCase;

/**
 * Qué pedidos puede pagar una transferencia ({@see ConsumerGroupTransfers::suggestions()})
 * y qué pasa con el pedido al asignarla, soltarla o apartarla. Los textos del banco son
 * los de La Caixa, con el nombre cortado como lo corta; nombres inventados.
 */
class ConsumerGroupTransfersTest extends TestCase
{
    private int $nextId = 1;

    private ConsumerGroupRound $round;

    private ConsumerGroupRoundItem $item;

    protected function setUp(): void
    {
        $this->round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED);
        $this->round->setConfirmed(true);
        $this->item = new ConsumerGroupRoundItem($this->round, new ConsumerGroupProduct(), '2.50');
    }

    /** El caso que el cruce asigna solo: importe exacto y el ordenante es de la familia. */
    public function testImporteExactoYNombreDeLaFamiliaEsSeguro(): void
    {
        $lucia = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '10');
        $other = $this->order(self::partner('PEDRO', 'MARTIN GIL'), '10');

        $found = ConsumerGroupTransfers::suggestions($this->transfer(2500, 'LUCIA ORTEGA PE'), [$other, $lucia], null);

        $this->assertSame($lucia, $found[0]['order'], 'El seguro va primero aunque se entregue después.');
        $this->assertTrue($found[0]['sure']);
        $this->assertSame(['Debe justo este importe', '«LUCIA ORTEGA PE» se parece a alguien de la familia'], $found[0]['reasons']);
        $this->assertSame($other, $found[1]['order'], 'Debe lo mismo: se propone, pero no es seguro.');
        $this->assertFalse($found[1]['sure']);
    }

    /**
     * Los pedidos de una ronda que deben justo el importe se agrupan: se leen una vez, no
     * uno por pedido. Los de nombre parecido pero otro importe forman su propio grupo.
     */
    public function testLasSugerenciasSeAgrupanPorImporteYRonda(): void
    {
        $lucia = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '10');
        $pedro = $this->order(self::partner('PEDRO', 'MARTIN GIL'), '10');
        $otroImporte = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');

        $found = ConsumerGroupTransfers::suggestions($this->transfer(2500, 'LUCIA ORTEGA PE'), [$otroImporte, $pedro, $lucia], null);
        $groups = ConsumerGroupTransfers::groupSuggestions($found);

        $this->assertCount(2, $groups);
        $this->assertTrue($groups[0]['exact']);
        $this->assertSame([$lucia, $pedro], array_map(static fn (array $s): ConsumerGroupOrder => $s['order'], $groups[0]['items']));
        $this->assertSame([true, false], array_map(static fn (array $s): bool => $s['named'], $groups[0]['items']));
        $this->assertFalse($groups[1]['exact']);
        $this->assertSame([$otroImporte], array_map(static fn (array $s): ConsumerGroupOrder => $s['order'], $groups[1]['items']));
        $this->assertSame([], ConsumerGroupTransfers::groupSuggestions([]));
    }

    /** El pedido está a nombre de quien encabeza la familia, y paga otra persona de ella. */
    public function testPagaOtraPersonaDeLaFamilia(): void
    {
        $head = self::partner('PEDRO', 'MARTIN GIL');
        $head->addRelative(self::partner('ROSA', 'LUNA SAEZ'));
        $order = $this->order($head, '4');

        $found = ConsumerGroupTransfers::suggestions($this->transfer(1000, 'ROSA LUNA SAEZ'), [$order], null);

        $this->assertTrue($found[0]['sure']);
    }

    /** Un familiar sin ficha: sólo casa porque ya pagó antes por esa familia. */
    public function testElOrdenanteAprendidoCasaSinParecerse(): void
    {
        $head = self::partner('PEDRO', 'MARTIN GIL');
        $order = $this->order($head, '4');

        $line = $this->transfer(1000, 'TOMASA GIL ROMERO');
        $this->assertSame([], array_filter(ConsumerGroupTransfers::suggestions($line, [$order], null), static fn (array $s): bool => $s['sure']));

        $found = ConsumerGroupTransfers::suggestions($line, [$order], $head);
        $this->assertTrue($found[0]['sure']);
        $this->assertSame('«TOMASA GIL ROMERO» ya pagó antes por esta familia', $found[0]['reasons'][1]);
    }

    /** De quien paga, pero otro importe: se propone (paga dos pedidos, o a cuenta), no es seguro. */
    public function testDeQuienPagaPeroOtroImporteSeProponeSinSeguridad(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');

        $found = ConsumerGroupTransfers::suggestions($this->transfer(2000, 'LUCIA ORTEGA PEREZ'), [$order], null);

        $this->assertFalse($found[0]['sure']);
        $this->assertSame('Debe 10,00 €, no este importe', $found[0]['reasons'][0]);
    }

    public function testNiImporteNiNombreNoSePropone(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');

        $this->assertSame([], ConsumerGroupTransfers::suggestions($this->transfer(1337, 'PERSONA DESCONOCIDA'), [$order], null));
    }

    public function testAsignarLaDejaPagadaPorTransferenciaElDiaDelBanco(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $transfer = new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ'));

        $transfer->assign([$order], true);

        $this->assertSame(ConsumerGroupPaymentMethod::Transfer, $order->getPaidVia());
        $this->assertSame('2026-11-05', $order->getPaidAt()?->format('Y-m-d'));
        $this->assertSame($transfer, $order->getTransfer());
        $this->assertSame([$order], $transfer->getOrders());
        $this->assertTrue($transfer->isAutomatic());
        $this->assertFalse($transfer->isPendingDecision());
    }

    /** Soltarla deja el pedido pendiente y el movimiento por elegir, no descartado. */
    public function testSoltarlaDejaElPedidoPendienteYElMovimientoPorElegir(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $transfer = (new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ')))->assign([$order], true);

        $transfer->release();

        $this->assertFalse($order->isPaid());
        $this->assertNull($order->getTransfer());
        $this->assertSame([], $transfer->getOrders());
        $this->assertTrue($transfer->isPendingDecision());
        $this->assertFalse($transfer->isAutomatic());
    }

    /**
     * Cambiar a mano cómo se pagó un pedido ya ligado a un movimiento rompe el vínculo:
     * si no, el movimiento seguiría «asignado» a un pedido que se cobró en metálico y
     * deshacerlo lo dejaría sin pagar.
     */
    public function testCambiarElPagoAManoSueltaElMovimiento(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $transfer = (new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ')))->assign([$order], true);

        $order->markPaid(ConsumerGroupPaymentMethod::Cash);

        $this->assertSame(ConsumerGroupPaymentMethod::Cash, $order->getPaidVia());
        $this->assertNull($order->getTransfer());
        $this->assertSame([], $transfer->getOrders());
        $this->assertTrue($transfer->isPendingDecision());
    }

    /** Si un pedido de la lista no se puede pagar, ninguno queda pagado a medias. */
    public function testAsignarVariosPedidosEsTodoONada(): void
    {
        $ok = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $paid = $this->order(self::partner('PEDRO', 'MARTIN GIL'), '4')->markPaid(ConsumerGroupPaymentMethod::Cash);
        $transfer = new ConsumerGroupTransfer($this->transfer(2000, 'LUCIA ORTEGA PEREZ'));

        try {
            $transfer->assign([$ok, $paid], false);
            $this->fail('Debería negarse.');
        } catch (\InvalidArgumentException) {
            $this->assertFalse($ok->isPaid());
            $this->assertNull($ok->getTransfer());
            $this->assertTrue($transfer->isPendingDecision());
        }
    }

    /** Un pedido de una ronda cancelada no se paga con un movimiento. */
    public function testNoSePagaUnPedidoDeUnaRondaCancelada(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $this->round->setStatus(ConsumerGroupRound::STATUS_CANCELLED);

        $this->expectException(\InvalidArgumentException::class);
        (new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ')))->assign([$order], false);
    }

    /** Desmarcar el pago desde la ronda también suelta el movimiento. */
    public function testDesmarcarElPagoDelPedidoLoSueltaDelMovimiento(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $transfer = (new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ')))->assign([$order], false);

        $order->markUnpaid();

        $this->assertSame([], $transfer->getOrders());
        $this->assertTrue($transfer->isPendingDecision());
    }

    public function testApartarlaYRecuperarla(): void
    {
        $order = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4');
        $transfer = (new ConsumerGroupTransfer($this->transfer(1000, 'LUCIA ORTEGA PEREZ')))->assign([$order], false);

        $transfer->dismiss();
        $this->assertFalse($order->isPaid(), 'Apartar suelta lo que tuviera.');
        $this->assertTrue($transfer->isDismissed());
        $this->assertFalse($transfer->isPendingDecision());

        $transfer->reopen();
        $this->assertTrue($transfer->isPendingDecision());
    }

    /** Pagar dos veces el mismo pedido lo cobraría dos veces: no se puede. */
    public function testNoSePagaUnPedidoYaPagadoNiUnoEnUnReciboEnviado(): void
    {
        $paid = $this->order(self::partner('LUCIA', 'ORTEGA PEREZ'), '4')->markPaid(ConsumerGroupPaymentMethod::Cash);
        $inReceipt = $this->order(self::partner('PEDRO', 'MARTIN GIL'), '4')->setRemittanceLine($this->sentReceiptLine());

        foreach ([$paid, $inReceipt] as $order) {
            try {
                (new ConsumerGroupTransfer($this->transfer(1000, 'X Y')))->assign([$order], false);
                $this->fail('Debería negarse.');
            } catch (\InvalidArgumentException) {
                $this->assertNull($order->getTransfer());
            }
        }
    }

    public function testSinPedidosNoSeAsigna(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ConsumerGroupTransfer($this->transfer(1000, 'X Y')))->assign([], false);
    }

    private static function partner(string $name, ?string $surname): Partner
    {
        return (new Partner())->setname($name)->setSurname($surname);
    }

    /** Un pedido de esa cantidad de kilos a 2,50 €. */
    private function order(Partner $partner, string $kilos): ConsumerGroupOrder
    {
        $order = new ConsumerGroupOrder($this->round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $this->item, $kilos));
        (new \ReflectionProperty(ConsumerGroupOrder::class, 'id'))->setValue($order, $this->nextId++);

        return $order;
    }

    /** Una transferencia recibida en La Caixa el 5 de noviembre, con ese ordenante. */
    private function transfer(int $cents, string $payer): BankStatementLine
    {
        $account = new FinancialAccount();
        (new \ReflectionProperty($account, 'id'))->setValue($account, 2);
        $day = new \DateTimeImmutable('2026-11-05');

        return new BankStatementLine($account, new Norma43Movement($day, $day, '02', '000', $cents, sprintf('%010d', $this->nextId++), '', '', ['TRANSF. A SU FAVOR', '14650100-' . $payer]), 1);
    }

    private function sentReceiptLine(): RemittanceLine
    {
        $payer = (new Partner())->setname('Ana')->setSurname('López')
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference('CUOTA MENSUAL 200')
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $remittance = new Remittance(Remittance::KIND_CONSUMER_GROUP, new \DateTimeImmutable('2026-10-01'), 'Grupo de consumo OCTUBRE 2026', Remittance::SOURCE_APP, null);
        $line = RemittanceLine::forPayer($payer, '10.00', 'GC 202610 CUOTA MENSUAL 200');
        $remittance->addLine($line);
        $remittance->markSent(null, new \DateTimeImmutable('2026-11-05'));

        return $line;
    }
}
