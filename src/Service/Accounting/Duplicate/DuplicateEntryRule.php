<?php

namespace App\Service\Accounting\Duplicate;

use App\Entity\AccountEntry;
use App\Service\Accounting\ConceptKey;

/**
 * Cuándo dos apuntes parecen el mismo pago anotado dos veces. Pasa cuando un pago
 * entra por dos caminos (la factura y el extracto, o el extracto y un apunte a mano)
 * y el segundo no ve el primero.
 *
 * La consulta trae ya los de la misma cuenta de banco, con el mismo importe, a pocos
 * días, que no son traspasos ni partes, y no ligados los dos a movimientos del banco
 * (si el banco trae dos, son dos pagos). Aquí se exige además que digan lo mismo: el
 * mismo concepto o la misma ficha de proveedor. Dos recibos de 33 € de proveedores
 * distintos el mismo día no son un duplicado.
 */
final class DuplicateEntryRule
{
    /** Días de diferencia hasta los que dos apuntes iguales se miran como posible duplicado. */
    public const DAYS = 3;

    /** Si dos apuntes candidatos dicen lo mismo: mismo concepto (sin cifras ni signos) o misma ficha. Puro. */
    public static function isLikely(AccountEntry $a, AccountEntry $b): bool
    {
        $key = ConceptKey::of($a->getConcept());
        $provider = $a->getProvider()?->getId();

        return ($key !== '' && $key === ConceptKey::of($b->getConcept()))
            || ($provider !== null && $provider === $b->getProvider()?->getId());
    }
}
