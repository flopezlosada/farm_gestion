<?php

namespace App\Tests\Service\Accounting;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Service\Accounting\TransferRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Arreglar a mano un traspaso suelto ({@see TransferRecorder::canPair},
 * {@see TransferRecorder::pair}, {@see TransferRecorder::complete}): lo que la portada
 * pide con «Revisar traspasos sin la otra mitad» y antes no se podía hacer.
 */
class TransferRecorderTest extends TestCase
{
    private BudgetCategory $transfer;
    private FinancialAccount $box;
    private FinancialAccount $bank;
    private TransferRecorder $recorder;

    protected function setUp(): void
    {
        $this->transfer = (new BudgetCategory())
            ->setName('Traspaso entre cuentas')
            ->setGroup((new BudgetCategoryGroup())->setName('TRASPASOS')->setKind(BudgetCategoryGroup::KIND_TRANSFER));
        $this->box = (new FinancialAccount())->setName('Caja');
        $this->bank = (new FinancialAccount())->setName('Banco');
        $this->recorder = new TransferRecorder($this->createStub(EntityManagerInterface::class));
    }

    /** Casan dos mitades sueltas de cuentas distintas con el mismo importe al revés; la fecha no cuenta. */
    public function testCasanLasMitadesSueltasDeImporteContrarioEnOtraCuenta(): void
    {
        $out = $this->entry($this->box, '2026-02-04', '-100.00');

        self::assertTrue(TransferRecorder::canPair($out, $this->entry($this->bank, '2026-02-15', '100.00')), 'El abono puede tardar.');
        self::assertFalse(TransferRecorder::canPair($out, $this->entry($this->bank, '2026-02-04', '115.00')), 'Otro importe: hay una errata.');
        self::assertFalse(TransferRecorder::canPair($out, $this->entry($this->box, '2026-02-04', '100.00')), 'Misma cuenta.');
        self::assertFalse(TransferRecorder::canPair($out, $this->entry($this->bank, '2026-02-04', '-100.00')), 'Mismo sentido.');
        self::assertFalse(TransferRecorder::canPair($out, $out));

        $notTransfer = $this->entry($this->bank, '2026-02-04', '100.00')->setCategory(
            (new BudgetCategory())->setName('Cuotas')->setGroup((new BudgetCategoryGroup())->setName('INGRESOS')->setKind(BudgetCategoryGroup::KIND_INCOME)),
        );
        self::assertFalse(TransferRecorder::canPair($out, $notTransfer), 'Sólo traspasos.');

        $taken = $this->entry($this->bank, '2026-02-04', '100.00');
        $taken->pairWith($this->entry($this->box, '2026-02-04', '-100.00'));
        self::assertFalse(TransferRecorder::canPair($out, $taken), 'Ya tiene pareja.');
    }

    public function testEmparejarEnlazaLasDosYLoQueNoCasaSeRechaza(): void
    {
        $out = $this->entry($this->box, '2026-02-04', '-100.00');
        $in = $this->entry($this->bank, '2026-02-05', '100.00');

        $this->recorder->pair($out, $in);

        self::assertSame($in, $out->getTransferPeer());
        self::assertSame($out, $in->getTransferPeer());
        self::assertTrue($out->isPairedTransfer());

        $this->expectException(\InvalidArgumentException::class);
        $this->recorder->pair($this->entry($this->box, '2026-02-04', '-100.00'), $this->entry($this->bank, '2026-02-04', '115.00'));
    }

    /** La mitad que falta: la otra cuenta, la misma fecha y concepto, el importe al revés, enlazada. */
    public function testCompletarAnotaLaOtraMitadEnlazada(): void
    {
        $in = $this->entry($this->bank, '2026-02-04', '115.00');

        $out = $this->recorder->complete($in, $this->box);

        self::assertSame($this->box, $out->getAccount());
        self::assertSame('-115.00', $out->getAmount());
        self::assertSame('2026-02-04', $out->getDate()->format('Y-m-d'));
        self::assertSame($in->getConcept(), $out->getConcept());
        self::assertSame($this->transfer, $out->getCategory());
        self::assertSame($out, $in->getTransferPeer());
        self::assertSame($in, $out->getTransferPeer());
    }

    public function testNoSeCompletaEnLaMismaCuentaNiUnTraspasoYaEmparejado(): void
    {
        $in = $this->entry($this->bank, '2026-02-04', '115.00');
        try {
            $this->recorder->complete($in, $this->bank);
            self::fail('La misma cuenta no puede ser la otra mitad.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($in->isLooseTransfer(), 'Sin tocar.');
        }

        $this->recorder->complete($in, $this->box);
        $this->expectException(\InvalidArgumentException::class);
        $this->recorder->complete($in, $this->box);
    }

    private function entry(FinancialAccount $account, string $date, string $amount): AccountEntry
    {
        return (new AccountEntry())
            ->setAccount($account)
            ->setCategory($this->transfer)
            ->setDate(new \DateTimeImmutable($date))
            ->setConcept('Traspaso')
            ->setAmount($amount);
    }
}
