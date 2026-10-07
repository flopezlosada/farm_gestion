<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\Provider;
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
     * @param list<array{base: string|null, rate: string|null, taxAmount: string|null}> $taxLines
     */
    public function __construct(
        public readonly ?string $documentType,
        public readonly ?\DateTimeImmutable $date,
        public readonly ?string $providerName,
        public readonly ?string $providerTaxId,
        public readonly ?string $invoiceNumber,
        public readonly ?string $total,
        public readonly array $taxLines,
        public readonly ?string $withholding,
        public readonly ?string $withholdingRate,
        public readonly ?string $providerAddress,
        public readonly ?string $providerPostalCode,
        public readonly ?string $providerTown,
        public readonly ?string $providerProvince,
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
            self::positiveMoney($raw['retencion_irpf'] ?? null),
            self::positiveMoney($raw['tipo_retencion'] ?? null),
            self::text($raw['direccion_proveedor'] ?? null, 255),
            self::postalCode($raw['cp_proveedor'] ?? null),
            self::text($raw['municipio_proveedor'] ?? null, 100),
            self::text($raw['provincia_proveedor'] ?? null, 100),
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
        return Provider::normalizeTaxId(self::text($value, 30));
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
     * Una retención o su tipo: se guardan en positivo aunque la factura la imprima
     * restando. Un cero es «no lleva», igual que no traerla.
     */
    private static function positiveMoney(mixed $value): ?string
    {
        $money = self::money(is_numeric($value) ? abs((float) $value) : null);

        return $money === null || (float) $money === 0.0 ? null : $money;
    }

    /** Sólo un código postal español con forma de serlo: cinco cifras. */
    private static function postalCode(mixed $value): ?string
    {
        $text = \is_int($value) ? sprintf('%05d', $value) : self::text($value, 10);

        return $text !== null && preg_match('/^\d{5}$/', $text) === 1 ? $text : null;
    }

    /**
     * Las líneas de IVA. Una línea sin ninguna cifra no dice nada y se descarta; una
     * a medias se queda, para que quien revisa complete lo que falta.
     *
     * @return list<array{base: string|null, rate: string|null, taxAmount: string|null}>
     */
    private static function taxLines(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $read = [
                'base' => self::money($line['base'] ?? null),
                'rate' => self::money($line['tipo_iva'] ?? null),
                'taxAmount' => self::money($line['cuota'] ?? null),
            ];
            if (array_filter($read, static fn (?string $v): bool => $v !== null) !== []) {
                $lines[] = $read;
            }
        }

        return $lines;
    }
}
