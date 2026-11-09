<?php

namespace App\Entity;

/**
 * Cómo paga su cuota quien encabeza una familia. Casi todas por recibo domiciliado,
 * que es lo que entra en la remesa; las demás no deben salir allí como «sin recibo»,
 * porque no es que falte nada: pagan de otra forma, o no pagan.
 *
 * Quien recibe una cesta que paga otra persona (una cesta donada) no es «no paga»:
 * eso es el pagador de la cesta, {@see PartnerBasketShare::getEffectivePayer()}.
 */
enum PartnerPaymentMethod: string
{
    /** Recibo domiciliado: necesita mandato y cuenta, y va en la remesa. */
    case Receipt = 'receipt';

    /** Paga por transferencia: tiene cuota, pero no recibo. */
    case Transfer = 'transfer';

    /** No paga (cobra en cestas, está exenta…): el motivo va en la ficha. */
    case NotCharged = 'none';

    /**
     * Para pantallas y formularios.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Recibo domiciliado',
            self::Transfer => 'Transferencia',
            self::NotCharged => 'No paga',
        };
    }
}
