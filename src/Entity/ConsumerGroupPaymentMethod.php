<?php

namespace App\Entity;

/**
 * Cómo se pagó UN pedido del grupo de consumo.
 *
 * No es {@see PartnerPaymentMethod}: aquélla dice cómo paga una familia su cuota de
 * cesta (y admite «no paga»); ésta dice cómo entró el dinero de un pedido concreto, y
 * un mismo pedido puede pagarse en metálico en el local aunque la socia tenga recibo.
 */
enum ConsumerGroupPaymentMethod: string
{
    /** En el recibo del grupo de consumo del mes: se pone al entrar el abono de la remesa en el banco. */
    case Receipt = 'receipt';

    /** Transferencia a la cuenta del grupo, con el concepto del pedido. */
    case Transfer = 'transfer';

    /** En metálico, en mano a la comisión. */
    case Cash = 'cash';

    /**
     * Para pantallas: «Pagado por …».
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'recibo',
            self::Transfer => 'transferencia',
            self::Cash => 'metálico',
        };
    }

    /**
     * Las que la comisión marca a mano. El recibo no: lo marca el abono de la remesa
     * en el banco, y marcarlo a mano diría «cobrado por recibo» de algo que ningún
     * banco ha cargado.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Transfer, self::Cash];
    }
}
