<?php

namespace App\Service\Accounting\Invoice;

/**
 * Un trimestre natural, que es el periodo en que se le entregan las facturas a la
 * gestoría (el IVA se declara por trimestres). Sólo puede valer del 1 al 4: un
 * trimestre imposible no se puede ni construir.
 */
final class InvoiceQuarter
{
    /**
     * @param int $year    Año.
     * @param int $quarter Trimestre, del 1 al 4.
     */
    private function __construct(
        public readonly int $year,
        public readonly int $quarter,
    ) {
    }

    /**
     * @param int $year    Año.
     * @param int $quarter Trimestre, del 1 al 4.
     *
     * @throws \InvalidArgumentException Si el trimestre no existe.
     */
    public static function of(int $year, int $quarter): self
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException(sprintf('No existe el trimestre %d.', $quarter));
        }

        return new self($year, $quarter);
    }

    /**
     * El último trimestre ya cerrado: el que toca entregar. En octubre, el tercero; en
     * enero, el cuarto del año anterior.
     *
     * @param \DateTimeInterface $today Hoy.
     */
    public static function lastClosed(\DateTimeInterface $today): self
    {
        $current = intdiv((int) $today->format('n') - 1, 3) + 1;
        $year = (int) $today->format('Y');

        return $current === 1 ? new self($year - 1, 4) : new self($year, $current - 1);
    }

    /** Primer día del trimestre. */
    public function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%d-%02d-01', $this->year, ($this->quarter - 1) * 3 + 1));
    }

    /** Último día del trimestre. */
    public function to(): \DateTimeImmutable
    {
        return $this->from()->modify('+3 months -1 day');
    }

    /** Como lo escribe la asociación en sus carpetas: «3T2026». */
    public function label(): string
    {
        return sprintf('%dT%d', $this->quarter, $this->year);
    }
}
