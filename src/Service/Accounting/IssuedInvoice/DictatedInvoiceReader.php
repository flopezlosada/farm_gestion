<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Entity\User;
use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;
use Symfony\Component\String\UnicodeString;

/**
 * Convierte un encargo dictado («factura a IMIDRA, 20 horas de formación, 1.600 €,
 * exenta»), por voz o por escrito, en un BORRADOR de factura emitida. Nunca en una
 * emitida: el número lo da quien la revisa y la emite en la web.
 *
 * El cliente se elige SÓLO entre los dados de alta: la respuesta de Gemini está
 * atada a sus claves en el esquema, así que no puede inventarse uno. Y no basta
 * con que lo elija: el nombre que se oyó tiene que nombrarlo de verdad
 * ({@see names()}). Con un solo cliente dado de alta, el esquema empuja al modelo a
 * elegirlo aunque se hable de otro, y su «estoy seguro» no vale (probado: «the
 * cooperative» acabó en IMIDRA, «seguro»). Si no lo nombra, el borrador queda sin
 * cliente y se devuelve el nombre oído.
 *
 * Los importes de las líneas son la BASE. Si se dicta con el IVA incluido, la base
 * se calcula aquí, no la calcula el modelo: una división hecha por un modelo de
 * lenguaje no es de fiar al céntimo.
 */
class DictatedInvoiceReader
{
    public const NONE = 'ninguno';

    /** Palabras que no distinguen a un cliente de otro. */
    private const GENERIC_WORDS = [
        'cooperativa', 'asociacion', 'ayuntamiento', 'comunidad', 'madrid', 'fundacion', 'instituto',
        'sociedad', 'grupo', 'centro', 'escuela', 'colegio', 'universidad', 'consejeria', 'direccion',
        'general', 'servicio', 'servicios', 'empresa', 'para', 'prueba',
    ];

    /**
     * @param GeminiClient $gemini Cliente de Gemini.
     */
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /** Si se puede leer: sin Gemini no hay quien entienda el encargo. */
    public function isAvailable(): bool
    {
        return $this->gemini->isConfigured();
    }

    /**
     * El borrador que sale de lo dictado, sin guardar.
     *
     * @param list<array<string, mixed>> $parts     Lo dictado: el audio ({@see GeminiClient::filePart()}) y/o `['text' => …]`.
     * @param list<Customer>             $customers Clientes entre los que elegir.
     * @param \DateTimeImmutable         $today     Fecha si no se dice otra.
     * @param User|null                  $by        Quién lo encarga.
     *
     * @throws GeminiException Si Gemini no ha contestado.
     */
    public function read(array $parts, array $customers, \DateTimeImmutable $today, ?User $by): DictatedInvoice
    {
        $byKey = [];
        foreach ($customers as $customer) {
            $byKey['c' . $customer->getId()] = $customer;
        }

        $result = $this->gemini->generateJson([...$parts, ['text' => $this->prompt($customers, $today)]], [
            'type' => 'OBJECT',
            'properties' => [
                'cliente' => ['type' => 'STRING', 'enum' => [...array_keys($byKey), self::NONE]],
                'cliente_oido' => ['type' => 'STRING'],
                'serie' => ['type' => 'STRING', 'enum' => [IssuedInvoice::SERIES_ORDINARY, IssuedInvoice::SERIES_X]],
                'fecha' => ['type' => 'STRING'],
                'descripcion' => ['type' => 'STRING'],
                'lineas' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'concepto' => ['type' => 'STRING'],
                            'importe' => ['type' => 'NUMBER'],
                            'iva' => ['type' => 'STRING', 'enum' => IssuedInvoiceLine::RATES],
                            'iva_incluido' => ['type' => 'BOOLEAN'],
                        ],
                        'required' => ['concepto', 'importe', 'iva'],
                    ],
                ],
            ],
            'required' => ['cliente', 'cliente_oido', 'serie', 'lineas'],
        ]);
        $data = $result['data'];

        $series = ($data['serie'] ?? null) === IssuedInvoice::SERIES_X ? IssuedInvoice::SERIES_X : IssuedInvoice::SERIES_ORDINARY;
        $invoice = new IssuedInvoice($series, IssuedInvoice::SOURCE_TELEGRAM, $by);
        $invoice->setIssueDate(self::date($data['fecha'] ?? null) ?? $today);
        $heard = self::text($data['cliente_oido'] ?? null);
        $chosen = $byKey[(string) ($data['cliente'] ?? self::NONE)] ?? null;
        $invoice->setCustomer($chosen !== null && self::names($heard, $chosen) ? $chosen : null);
        $invoice->setDescription(self::text($data['descripcion'] ?? null));

        foreach ((array) ($data['lineas'] ?? []) as $line) {
            $concept = self::text($line['concepto'] ?? null);
            $rate = (string) ($line['iva'] ?? '');
            if ($concept === null || !\in_array($rate, IssuedInvoiceLine::RATES, true) || !is_numeric($line['importe'] ?? null)) {
                continue;
            }
            $invoice->addLine(new IssuedInvoiceLine($concept, self::base((float) $line['importe'], $rate, ($line['iva_incluido'] ?? false) === true), $rate));
        }

        return new DictatedInvoice($invoice, $invoice->getCustomer() === null ? $heard : null);
    }

    /**
     * La base de una línea: el importe tal cual, o sin el IVA si se dijo incluido.
     *
     * @param float  $amount   Importe dicho.
     * @param string $rate     Tipo de IVA.
     * @param bool   $included Si el importe dicho lleva el IVA dentro.
     */
    public static function base(float $amount, string $rate, bool $included): string
    {
        $cents = IssuedInvoice::toCents((string) $amount);
        if ($included && (int) $rate > 0) {
            $cents = (int) round($cents * 100 / (100 + (int) $rate));
        }

        return IssuedInvoice::fromCents($cents);
    }

    /**
     * Las instrucciones: qué sacar y con qué criterios.
     *
     * @param list<Customer>     $customers Clientes dados de alta.
     * @param \DateTimeImmutable $today     Hoy, para entender «ayer» o «el día 3».
     */
    private function prompt(array $customers, \DateTimeImmutable $today): string
    {
        $list = $customers === []
            ? '(no hay ninguno dado de alta)'
            : implode("\n", array_map(
                static fn (Customer $c): string => sprintf('- c%d: %s%s', $c->getId(), $c->getName(), $c->getTaxId() !== null ? ' (CIF ' . $c->getTaxId() . ')' : ''),
                $customers,
            ));

        return "Alguien de la asociación CSA Vega de Jarama pide HACER una factura que la asociación cobra a un cliente. Hoy es {$today->format('Y-m-d')}.\n"
            . "Clientes dados de alta:\n{$list}\n"
            . 'En "cliente" elige la clave del cliente del que habla, o "' . self::NONE . '" si no es ninguno de la lista; en "cliente_oido", el nombre tal como lo ha dicho. '
            . 'En "serie": "' . IssuedInvoice::SERIES_ORDINARY . '" si es formación (cursos, horas de formación, inmersiones, visitas didácticas), "' . IssuedInvoice::SERIES_X . '" si es otra cosa. '
            . 'En "fecha", la fecha de la factura en formato AAAA-MM-DD sólo si la dice; si no, déjala vacía. '
            . 'En "descripcion", una frase que resuma de qué es, si la hay. '
            . 'En "lineas", una por concepto con su importe en euros como número (1.600 € es 1600). '
            . '"iva": "0" si dice exenta o sin IVA; si no dice nada, "0" en formación y "21" en lo demás. '
            . '"iva_incluido": true sólo si dice que el importe ya lleva el IVA ("IVA incluido", "en total").';
    }

    /**
     * Si lo oído nombra a ese cliente: comparten una palabra distintiva (de cuatro
     * letras o más, sin contar las que llevan media administración y media
     * economía social) o lo oído trae su CIF. Sin tildes ni mayúsculas.
     *
     * @param string|null $heard    Nombre tal como se dijo.
     * @param Customer    $customer Cliente elegido.
     */
    public static function names(?string $heard, Customer $customer): bool
    {
        if ($heard === null) {
            return false;
        }
        $taxId = $customer->getTaxId();
        if ($taxId !== null && str_contains(preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($heard)), $taxId)) {
            return true;
        }

        return array_intersect(self::words($heard), self::words($customer->getName())) !== [];
    }

    /**
     * Las palabras distintivas de un nombre.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $plain = (new UnicodeString($name))->ascii()->lower()->toString();
        $words = preg_split('/[^a-z0-9]+/', $plain, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            static fn (string $w): bool => \strlen($w) >= 4 && !\in_array($w, self::GENERIC_WORDS, true),
        ));
    }

    /** «2026-06-30» → fecha; cualquier otra cosa, null. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Texto recortado, o null si viene vacío. */
    private static function text(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
