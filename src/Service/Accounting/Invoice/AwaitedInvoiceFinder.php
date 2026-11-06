<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\BankStatementLine;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Repository\ReceivedInvoiceRepository;

/**
 * La factura aceptada que espera el pago de un movimiento del banco: la que se paga
 * con ese mismo importe. Al anotar el movimiento, el apunte se engancha a ella y
 * toma su partida, su proveedor y su número.
 *
 * Si varias esperan el mismo importe, va primero la del proveedor que el banco
 * nombra (por la ficha de otras veces o por su nombre en el concepto) y, entre
 * iguales, la más antigua: se pagan por orden. Es una propuesta: quien anota la ve y
 * puede decir que no es.
 */
class AwaitedInvoiceFinder
{
    /** Palabras del nombre del proveedor que no lo distinguen de otro. */
    private const MIN_WORD = 4;

    public function __construct(private readonly ReceivedInvoiceRepository $invoices)
    {
    }

    /**
     * La factura que paga este movimiento, o null si ninguna lo espera.
     *
     * @param BankStatementLine $line     Movimiento del banco, aún sin apunte.
     * @param Provider|null     $provider Proveedor que se le supone por otras veces.
     */
    public function for(BankStatementLine $line, ?Provider $provider): ?ReceivedInvoice
    {
        return self::pick($line, $provider, $this->invoices->findAwaitingPayment($line->getAmount()));
    }

    /**
     * Elige entre las que esperan ese importe. Puro.
     *
     * @param BankStatementLine     $line       Movimiento del banco.
     * @param Provider|null         $provider   Proveedor que se le supone por otras veces.
     * @param list<ReceivedInvoice> $candidates Las de ese importe, las más antiguas primero.
     */
    public static function pick(BankStatementLine $line, ?Provider $provider, array $candidates): ?ReceivedInvoice
    {
        $text = self::normalize(implode(' ', [$line->getSuggestedConcept(), (string) $line->getDetails()]));
        $named = static fn (ReceivedInvoice $i): bool => ($provider !== null && $i->getProvider() === $provider)
            || self::mentions($text, $i->getProvider()?->getName() ?? $i->getProviderName());

        return array_values(array_filter($candidates, $named))[0] ?? $candidates[0] ?? null;
    }

    /** Si el texto del banco lleva alguna palabra distintiva del nombre. */
    private static function mentions(string $text, ?string $name): bool
    {
        $words = array_filter(
            preg_split('/\W+/u', self::normalize((string) $name)) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD,
        );

        return array_filter($words, static fn (string $word): bool => str_contains($text, $word)) !== [];
    }

    /** En mayúsculas y sin tildes: el banco escribe «FERRETERIA», la factura «Ferretería». */
    private static function normalize(string $value): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

        return mb_strtoupper($plain !== false ? $plain : $value);
    }
}
