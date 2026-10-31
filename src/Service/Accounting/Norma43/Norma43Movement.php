<?php

namespace App\Service\Accounting\Norma43;

/**
 * Un movimiento del extracto (registro 22 con sus complementarios 23). El importe va
 * en céntimos y con signo, como en el libro: negativo si sale de la cuenta (debe),
 * positivo si entra (haber).
 */
final class Norma43Movement
{
    /** Conceptos comunes (Anexo 2 del cuaderno 43) que suelen juntar varios apuntes en uno. */
    public const GROUPED_CONCEPTS = [
        '06' => 'remesa de efectos',
        '12' => 'tarjeta',
        '14' => 'devolución o impagado',
    ];

    /**
     * @param \DateTimeImmutable $operationDate Fecha en que se asentó en la cuenta.
     * @param \DateTimeImmutable $valueDate     Fecha valor (a efectos de intereses).
     * @param string             $commonConcept Concepto común interbancario, dos cifras.
     * @param string             $ownConcept    Concepto propio de la entidad, tres cifras.
     * @param int                $amountCents   Importe con signo, en céntimos.
     * @param string             $document      Nº de documento, sin blancos a los lados.
     * @param string             $reference1    Referencia 1, sin blancos a los lados.
     * @param string             $reference2    Referencia 2, sin blancos a los lados.
     * @param list<string>       $texts         Textos de los registros 23, sólo para enseñar.
     */
    public function __construct(
        public readonly \DateTimeImmutable $operationDate,
        public readonly \DateTimeImmutable $valueDate,
        public readonly string $commonConcept,
        public readonly string $ownConcept,
        public readonly int $amountCents,
        public readonly string $document,
        public readonly string $reference1,
        public readonly string $reference2,
        public readonly array $texts,
    ) {
    }

    /**
     * Lo que identifica al movimiento, sin el ordinal: dos movimientos con la misma clave
     * son indistinguibles. Sólo fecha e importe, porque es lo único que traen igual el
     * Norma 43 y la hoja de Excel: con documento y referencias, el mismo movimiento
     * subido una vez en cada formato (octubre en la hoja del año y luego en el Norma 43
     * del mes) entraría dos veces.
     */
    public function identity(): string
    {
        return $this->operationDate->format('Y-m-d').'|'.$this->amountCents;
    }
}
