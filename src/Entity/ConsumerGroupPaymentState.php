<?php

namespace App\Entity;

/**
 * En qué punto está el pago de un pedido del grupo de consumo, para pintarlo. No se
 * guarda: sale del pedido ({@see ConsumerGroupOrder::getPaymentState()}).
 */
enum ConsumerGroupPaymentState: string
{
    /** Pagado, de la forma que sea. */
    case Paid = 'paid';

    /** Va en un recibo ya enviado: falta que el abono entre en el banco. */
    case AwaitingBank = 'awaiting_bank';

    /** Se le cobrará en el recibo del mes: tiene el mandato completo. */
    case InReceipt = 'in_receipt';

    /** El banco devolvió el recibo y la devolución no ha acabado en un pago. */
    case Returned = 'returned';

    /** Sin pagar, y no va en ningún recibo: tiene que pagarlo ella. */
    case Pending = 'pending';
}
