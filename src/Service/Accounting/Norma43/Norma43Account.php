<?php

namespace App\Service\Accounting\Norma43;

/**
 * Una cuenta dentro de un extracto Norma 43: su cabecera (11), sus movimientos (22) y
 * el cierre (33), ya comprobados entre sí. Los saldos, en céntimos y con signo.
 */
final class Norma43Account
{
    /**
     * @param string                $bank         Clave de la entidad, cuatro cifras.
     * @param string                $branch       Clave de la oficina, cuatro cifras.
     * @param string                $number       Nº de cuenta dentro de la oficina, diez cifras.
     * @param \DateTimeImmutable    $from         Primer día que cubre el extracto.
     * @param \DateTimeImmutable    $to           Último día que cubre.
     * @param int                   $openingCents Saldo al empezar el día $from.
     * @param int                   $closingCents Saldo al acabar el día $to.
     * @param string                $holder       Nombre abreviado del titular.
     * @param list<Norma43Movement> $movements    En el orden del fichero.
     */
    public function __construct(
        public readonly string $bank,
        public readonly string $branch,
        public readonly string $number,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly int $openingCents,
        public readonly int $closingCents,
        public readonly string $holder,
        public readonly array $movements,
    ) {
    }

    /** Entidad + oficina + cuenta: lo que se compara con el IBAN de las cuentas del libro. */
    public function key(): string
    {
        return $this->bank.$this->branch.$this->number;
    }

    /** La cuenta para enseñarla sin el número entero: «2100 0418 ····1234». */
    public function label(): string
    {
        return sprintf('%s %s ····%s', $this->bank, $this->branch, substr($this->number, -4));
    }
}
