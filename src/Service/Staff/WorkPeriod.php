<?php

namespace App\Service\Staff;

/**
 * Un periodo de meses seguidos del registro de jornada, del primero al último,
 * los dos incluidos. Existe para que un periodo al revés o desmesurado no se
 * pueda ni construir: dos meses sueltos dejan pasar «de octubre a enero» y el
 * error sólo se ve con un documento vacío.
 */
final class WorkPeriod
{
    /**
     * Tope de meses: cinco años. La ley pide conservar el registro cuatro
     * (art. 34.9 ET); el margen deja pedir los cuatro años enteros desde
     * cualquier mes, y el tope frena un periodo absurdo tecleado a mano.
     */
    public const MAX_MONTHS = 60;

    private function __construct(
        public readonly WorkMonth $from,
        public readonly WorkMonth $to,
    ) {
    }

    /**
     * @param WorkMonth $from Primer mes, incluido.
     * @param WorkMonth $to   Último mes, incluido.
     * @return self
     *
     * @throws \InvalidArgumentException Si el periodo va al revés o pasa del tope.
     */
    public static function between(WorkMonth $from, WorkMonth $to): self
    {
        if ($from->start() > $to->start()) {
            throw new \InvalidArgumentException(sprintf('El periodo empieza (%s) después de terminar (%s).', $from->label(), $to->label()));
        }

        $period = new self($from, $to);
        if ($period->length() > self::MAX_MONTHS) {
            throw new \InvalidArgumentException(sprintf('Un periodo no puede pasar de %d meses.', self::MAX_MONTHS));
        }

        return $period;
    }

    /**
     * El periodo de un único mes.
     *
     * @param WorkMonth $month El mes.
     * @return self
     */
    public static function single(WorkMonth $month): self
    {
        return new self($month, $month);
    }

    /**
     * Los meses del periodo, en orden.
     *
     * @return WorkMonth[]
     */
    public function months(): array
    {
        $months = [];
        for ($month = $this->from; $month->start() <= $this->to->start(); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * El periodo partido por años naturales: de marzo de 2023 a junio de 2025
     * salen marzo-diciembre de 2023, todo 2024 y enero-junio de 2025.
     *
     * @return self[]
     */
    public function byYear(): array
    {
        return array_map(
            fn (int $year): self => new self(
                $year === $this->from->year ? $this->from : WorkMonth::of($year, 1),
                $year === $this->to->year ? $this->to : WorkMonth::of($year, 12),
            ),
            range($this->from->year, $this->to->year),
        );
    }

    /**
     * ¿Cabe en un solo año natural?
     *
     * @return bool
     */
    public function isWithinOneYear(): bool
    {
        return $this->from->year === $this->to->year;
    }

    /**
     * @return int Cuántos meses tiene.
     */
    public function length(): int
    {
        return ($this->to->year - $this->from->year) * 12 + $this->to->month - $this->from->month + 1;
    }

    /**
     * Primer instante del periodo (Madrid), incluido.
     *
     * @return \DateTimeImmutable
     */
    public function start(): \DateTimeImmutable
    {
        return $this->from->start();
    }

    /**
     * Primer instante tras el periodo, excluido: el periodo es [start, end).
     *
     * @return \DateTimeImmutable
     */
    public function end(): \DateTimeImmutable
    {
        return $this->to->end();
    }

    /**
     * El periodo en castellano: «octubre de 2026» si es un mes, «de enero de
     * 2026 a octubre de 2026» si son varios.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->from->equals($this->to) ? $this->from->label() : sprintf('de %s a %s', $this->from->label(), $this->to->label());
    }

    /**
     * Clave estable para nombres de fichero ('2026-01_2026-10', o '2026-03' si es un mes).
     *
     * @return string
     */
    public function key(): string
    {
        return $this->from->equals($this->to) ? $this->from->key() : $this->from->key() . '_' . $this->to->key();
    }
}
