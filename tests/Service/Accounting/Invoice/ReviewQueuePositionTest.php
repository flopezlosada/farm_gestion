<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\ReviewQueuePosition;
use PHPUnit\Framework\TestCase;

/**
 * Ir de una factura a otra de las que esperan revisión: sus vecinas y su puesto, esté
 * o no en la cola.
 */
class ReviewQueuePositionTest extends TestCase
{
    /** En medio de la cola: la de antes, la de después y su puesto. */
    public function testEnMedioTieneLasDosVecinasYSuPuesto(): void
    {
        [$a, $b, $c] = [$this->invoice(3), $this->invoice(7), $this->invoice(12)];

        $position = ReviewQueuePosition::of($b, [$a, $b, $c]);

        $this->assertSame($a, $position->previous);
        $this->assertSame($c, $position->next);
        $this->assertSame(2, $position->position);
        $this->assertSame(3, $position->total);
    }

    /** En los extremos falta la vecina de ese lado; con una sola, ninguna. */
    public function testEnLosExtremosFaltaUnaVecina(): void
    {
        [$a, $b] = [$this->invoice(3), $this->invoice(7)];

        $this->assertNull(ReviewQueuePosition::of($a, [$a, $b])->previous);
        $this->assertSame($b, ReviewQueuePosition::of($a, [$a, $b])->next);
        $this->assertNull(ReviewQueuePosition::of($b, [$a, $b])->next);

        $alone = ReviewQueuePosition::of($a, [$a]);
        $this->assertNull($alone->previous);
        $this->assertNull($alone->next);
        $this->assertSame(1, $alone->position);
    }

    /**
     * Una que no está en la cola (recién anotada, o aún sin leer) no tiene puesto, pero
     * sí vecinas: las de antes y después de su id. Así, al anotar una se sigue por la
     * que venía detrás y no se vuelve a la primera que se dejó atrás.
     */
    public function testFueraDeLaColaSusVecinasSonLasDeSuAlrededor(): void
    {
        [$a, $c] = [$this->invoice(3), $this->invoice(12)];

        $position = ReviewQueuePosition::of($this->invoice(7), [$a, $c]);

        $this->assertSame($a, $position->previous);
        $this->assertSame($c, $position->next);
        $this->assertNull($position->position);
        $this->assertSame(2, $position->total);
    }

    /** Con la cola vacía no hay adónde ir. */
    public function testConLaColaVaciaNoHayVecinas(): void
    {
        $position = ReviewQueuePosition::of($this->invoice(7), []);

        $this->assertNull($position->previous);
        $this->assertNull($position->next);
        $this->assertNull($position->position);
        $this->assertSame(0, $position->total);
    }

    /**
     * Una factura con su id, como si viniera de la base.
     *
     * @param int $id Su id.
     */
    private function invoice(int $id): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        (new \ReflectionProperty(ReceivedInvoice::class, 'id'))->setValue($invoice, $id);

        return $invoice;
    }
}
