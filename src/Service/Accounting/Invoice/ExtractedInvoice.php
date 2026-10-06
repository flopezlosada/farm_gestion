<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;

/**
 * Lo que se ha leído de una factura, ya saneado.
 *
 * La respuesta del lector se trata como lo que es, texto que viene de fuera: aquí
 * se convierte a tipos y lo que no encaja se queda en null en vez de colarse. Un
 * total que no es número, una fecha imposible o una forma de pago inventada no
 * llegan a la base.
 */
final class ExtractedInvoice
{
    private const DOCUMENT_TYPES = ['factura', 'factura_simplificada', 'ticket', 'otro'];

    private const CONFIDENCES = ['alta', 'media', 'baja'];

    /**
     * @param list<array{base: float|null, tipo_iva: float|null, cuota: float|null}> $taxLines
     */
    public function __construct(
        public readonly ?string $documentType,
        public readonly ?\DateTimeImmutable $date,
        public readonly ?string $providerName,
        public readonly ?string $providerTaxId,
        public readonly ?string $invoiceNumber,
        public readonly ?string $total,
        public readonly array $taxLines,
        public readonly ?string $concept,
        public readonly string $paymentMethod,
        public readonly ?string $confidence,
        public readonly ?int $categoryId,
    ) {
    }

    /**
     * Construye el resultado a partir del JSON que devuelve el lector.
     *
     * @param array<string, mixed> $raw Respuesta decodificada.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::oneOf($raw['tipo'] ?? null, self::DOCUMENT_TYPES),
            self::date($raw['fecha'] ?? null),
            self::text($raw['proveedor'] ?? null, 150),
            self::taxId($raw['cif_proveedor'] ?? null),
            self::text($raw['numero_factura'] ?? null, 50),
            self::money($raw['total'] ?? null),
            self::taxLines($raw['lineas_iva'] ?? null),
            self::text($raw['concepto'] ?? null, 255),
            self::oneOf($raw['forma_pago'] ?? null, array_keys(ReceivedInvoice::PAYMENT_LABELS)) ?? ReceivedInvoice::PAYMENT_UNKNOWN,
            self::oneOf($raw['confianza'] ?? null, self::CONFIDENCES),
            isset($raw['partida_id']) && is_numeric($raw['partida_id']) ? (int) $raw['partida_id'] : null,
        );
    }

    /**
     * @param mixed        $value   Valor leído.
     * @param list<string> $allowed Valores admitidos.
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }

    /** Texto recortado, o null si viene vacío. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** CIF/NIF en mayúsculas y sin espacios ni guiones, que es como se compara. */
    private static function taxId(mixed $value): ?string
    {
        $text = self::text($value, 30);
        if ($text === null) {
            return null;
        }
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));

        return $clean === '' ? null : mb_substr($clean, 0, 20);
    }

    /** Fecha en formato AAAA-MM-DD, y sólo si existe en el calendario. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Importe con dos decimales como texto, que es como lo guarda Doctrine. */
    private static function money(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * @return list<array{base: float|null, tipo_iva: float|null, cuota: float|null}>
     */
    private static function taxLines(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $number = static fn (mixed $v): ?float => is_numeric($v) ? round((float) $v, 2) : null;
        $lines = [];
        foreach ($value as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $lines[] = [
                'base' => $number($line['base'] ?? null),
                'tipo_iva' => $number($line['tipo_iva'] ?? null),
                'cuota' => $number($line['cuota'] ?? null),
            ];
        }

        return $lines;
    }
}
