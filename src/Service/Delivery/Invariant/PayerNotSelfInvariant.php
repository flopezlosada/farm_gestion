<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketShare;

/**
 * L26 — Donación sana: una cesta donada delega el cobro en OTRO socio;
 * payer_partner apuntando al propio titular es dato sin sentido (la donación
 * a uno mismo no existe). Que el pagador siga activo lo vigila ya
 * ValidatePartnersConsistencyCommand; aquí solo la auto-referencia.
 * Las leyes de cobro real llegarán con la fase PAGOS.
 */
final class PayerNotSelfInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L26';
    }

    public function name(): string
    {
        return 'Donación sana (el pagador no es el propio socio)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname
             FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             WHERE pbs.is_active = 1 AND IDENTITY(pbs.payer_partner) = p.id'
        )->getArrayResult();

        return array_map(
            static fn (array $r): string => sprintf(
                '%s (%d): su cesta se dona... a sí mismo (payer_partner = titular).',
                $r['pname'],
                $r['pid']
            ),
            $rows
        );
    }
}
