<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\BankStatementLine;

/**
 * Quién ordenó una transferencia recibida, según lo que pone el banco en el
 * movimiento. Cada banco lo escribe a su manera; los formatos vistos en los
 * extractos reales:
 *
 *  - Fiare: «TRF. NOMBRE APELLIDOS» en la primera línea;
 *  - La Caixa: «TRANSF. A SU FAVOR» y en la segunda línea «<entidad>-NOMBRE»
 *    (cortado a 23 caracteres, la «Ñ» como espacio), o «TRANSFER INMEDIATA» y el
 *    nombre en la segunda.
 *
 * Un banco nuevo es un patrón más aquí. Lo que no sea una transferencia recibida
 * (un traspaso, un abono de remesa) no tiene ordenante.
 */
final class TransferPayer
{
    /** Un patrón por formato de banco; el grupo `name` es el ordenante. */
    private const FORMATS = [
        '/^TRF\.\s+(?<name>[^\r\n]+)/u',
        '/^TRANSF\. A SU FAVOR\s*\R(?:\d+-)?(?<name>[^\r\n]+)/u',
        '/^TRANSFER INMEDIATA\s*\R(?<name>[^\r\n]+)/u',
    ];

    /**
     * El ordenante del movimiento, o null si no es una transferencia recibida.
     */
    public static function of(BankStatementLine $line): ?string
    {
        return $line->getAmountCents() > 0 ? self::fromDetails((string) $line->getDetails()) : null;
    }

    /**
     * El ordenante según el texto del banco, o null si no tiene el de ningún formato
     * conocido. Puro.
     */
    public static function fromDetails(string $details): ?string
    {
        foreach (self::FORMATS as $format) {
            if (preg_match($format, trim($details), $m) === 1) {
                $name = trim((string) preg_replace('/\s+/u', ' ', $m['name']));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
