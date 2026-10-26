<?php

namespace App\Service\Accounting\Remittance;

/**
 * Lo que se puede hacer con un recibo que no casa con su ficha
 * ({@see RemittanceCases}), uno a uno desde la ficha de la remesa.
 */
enum CaseAction: string
{
    /** Es de esta ficha. */
    case Link = 'link';

    /** Es de esta ficha, y su nombre se corrige con lo que dice el recibo. */
    case LinkAndRename = 'link_rename';

    /** No es de la ficha con la que está enlazado. */
    case Unlink = 'unlink';

    /** Lo bueno es lo del recibo: su cuenta y su mandato van a la ficha. */
    case TakeReceipt = 'take';

    /** Lo bueno es lo que ya hay (o paga la cesta de alguien sin ficha): se deja así. */
    case Keep = 'keep';

    /** Volver a presentarlo como caso. */
    case Reopen = 'reopen';

    /** Si cambia la ficha del socix y no sólo el recibo: pide permiso de socixs. */
    public function touchesPartner(): bool
    {
        return $this === self::LinkAndRename || $this === self::TakeReceipt;
    }
}
