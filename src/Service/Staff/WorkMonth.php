<?php

namespace App\Service\Staff;

/**
 * Un mes del registro de jornada: la unidad que se aprueba, se bloquea y se
 * resume. Existe para que "año y mes" viajen juntos y validados: dos enteros
 * sueltos dejan pasar un mes 13 o un año y mes cruzados entre llamadas, y el
 * error sólo se ve al pintar.
 *
 * Los límites van siempre en hora de Madrid, como el resto del registro.
 */
final class WorkMonth
{
    private const TZ = 'Europe/Madrid';

    private function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
    }

    /**
     * @param int $year  Año.
     * @param int $month Mes (1-12).
     * @return self
     *
     * @throws \InvalidArgumentException Si el mes no existe.
     */
    public static function of(int $year, int $month): self
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException(sprintf('Mes no válido: %d-%d.', $year, $month));
        }

        return new self($year, $month);
    }

    /**
     * El mes al que pertenece un instante, contado en hora de Madrid.
     *
     * @param \DateTimeInterface $at Instante.
     * @return self
     */
    public static function containing(\DateTimeInterface $at): self
    {
        $local = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone(self::TZ));

        return new self((int) $local->format('Y'), (int) $local->format('n'));
    }

    /**
     * Primer instante del mes (día 1 a las 00:00, Madrid), incluido.
     *
     * @return \DateTimeImmutable
     */
    public function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $this->year, $this->month), new \DateTimeZone(self::TZ));
    }

    /**
     * Primer instante del mes siguiente, excluido: el mes es [start, end).
     *
     * @return \DateTimeImmutable
     */
    public function end(): \DateTimeImmutable
    {
        return $this->start()->modify('first day of next month');
    }

    /**
     * @return self El mes anterior.
     */
    public function previous(): self
    {
        return self::containing($this->start()->modify('-1 day'));
    }

    /**
     * @return self El mes siguiente.
     */
    public function next(): self
    {
        return self::containing($this->end());
    }

    /**
     * ¿Ha terminado ya el mes en ese instante? Sólo un mes terminado se puede
     * aprobar: aprobar el mes en curso dejaría fuera lo que aún se va a fichar.
     *
     * @param \DateTimeInterface $now Ahora.
     * @return bool
     */
    public function hasEndedAt(\DateTimeInterface $now): bool
    {
        return $now >= $this->end();
    }

    /**
     * Clave estable 'YYYY-MM', para referencias de idempotencia y URLs.
     *
     * @return string
     */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /**
     * Nombre del mes en castellano con el año ("marzo de 2026"). El filtro
     * `date` de Twig daría el nombre en inglés.
     *
     * @return string
     */
    public function label(): string
    {
        $names = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return sprintf('%s de %d', $names[$this->month], $this->year);
    }

    /**
     * @param self $other Otro mes.
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }
}
