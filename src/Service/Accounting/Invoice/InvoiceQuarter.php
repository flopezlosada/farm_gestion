<?php

namespace App\Service\Accounting\Invoice;

/**
 * Un trimestre natural, que es el periodo en que se le entregan las facturas a la
 * gestoría (el IVA se declara por trimestres). Sólo puede valer del 1 al 4: un
 * trimestre imposible no se puede ni construir.
 */
final class InvoiceQuarter
{
    /** Día del mes siguiente al trimestre en que la gestoría quiere el paquete. */
    public const DELIVERY_DAY = 5;

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
     * El trimestre en que cae un día.
     *
     * @param \DateTimeInterface $date El día.
     */
    public static function containing(\DateTimeInterface $date): self
    {
        return new self((int) $date->format('Y'), intdiv((int) $date->format('n') - 1, 3) + 1);
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

    /** Primer día después del trimestre: desde ese momento se puede entregar. */
    public function dayAfter(): \DateTimeImmutable
    {
        return $this->from()->modify('+3 months');
    }

    /**
     * Si el trimestre ya ha terminado en ese instante. Se compara con {@see dayAfter()},
     * no con {@see to()}: el último día a mediodía aún es del trimestre.
     */
    public function hasEnded(\DateTimeInterface $now): bool
    {
        return $now >= $this->dayAfter();
    }

    /**
     * Último día para entregar el trimestre a la gestoría: el 5 del mes siguiente, para
     * que le dé tiempo a presentar los modelos trimestrales (que vencen el 20, y el 30
     * de enero los del cuarto). Es el plazo que pide la gestoría, no el de Hacienda.
     */
    public function deliveryDeadline(): \DateTimeImmutable
    {
        return $this->dayAfter()->modify(sprintf('+%d days', self::DELIVERY_DAY - 1));
    }

    /** Como lo escribe la asociación en sus carpetas: «3T2026». */
    public function label(): string
    {
        return sprintf('%dT%d', $this->quarter, $this->year);
    }
}
