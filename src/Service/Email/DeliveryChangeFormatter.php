<?php

namespace App\Service\Email;

use App\Entity\PartnerEvent;

/**
 * Traduce los {@see PartnerEvent} de cambios autoservicio a filas legibles para
 * el email de resumen a administración.
 *
 * Lógica compartida por {@see \App\Command\SendAdminDeliveryChangesSummaryCommand}
 * (que la usa para el envío real) y por la previsualización de la plantilla en
 * el diagnóstico de envíos, para no duplicar el formateo.
 */
class DeliveryChangeFormatter
{
    /**
     * Tipos de PartnerEvent que cuentan como "cambio autoservicio relevante para
     * admin". Otros tipos (JOIN, LEAVE, etc.) ya tienen sus propios flujos.
     */
    public const RELEVANT_TYPES = [
        PartnerEvent::TYPE_BASKET_SKIP,
        PartnerEvent::TYPE_BASKET_UNSKIP,
        PartnerEvent::TYPE_NODE_CHANGE,
        PartnerEvent::TYPE_WEEK_SWAP,
    ];

    /**
     * Convierte el tipo del evento a una etiqueta breve para humanos. Tiene en
     * cuenta el flag `cancelled` del payload para distinguir un WEEK_SWAP de su
     * reversión.
     *
     * @param array<string, mixed> $payload
     */
    public function humanType(string $type, array $payload): string
    {
        return match ($type) {
            PartnerEvent::TYPE_BASKET_SKIP => 'No recoge cesta',
            PartnerEvent::TYPE_BASKET_UNSKIP => 'Vuelve a recoger',
            PartnerEvent::TYPE_NODE_CHANGE => 'Cambia de nodo',
            PartnerEvent::TYPE_WEEK_SWAP => ($payload['cancelled'] ?? false) ? 'Cancela cambio de viernes' : 'Cambia de viernes',
            default => $type,
        };
    }

    /**
     * Detalle textual con los datos del payload, listo para mostrar al admin.
     *
     * @param array<string, mixed> $payload
     */
    public function humanDescription(string $type, array $payload): string
    {
        return match ($type) {
            PartnerEvent::TYPE_NODE_CHANGE => sprintf(
                '%s → %s',
                $payload['from_group_name'] ?? '—',
                $payload['to_group_name'] ?? '—',
            ),
            PartnerEvent::TYPE_WEEK_SWAP => sprintf(
                'del %s al %s',
                $payload['from_date'] ?? '—',
                $payload['to_date'] ?? '—',
            ),
            default => '',
        };
    }

    /**
     * Fila precomputada para el template del email — Twig 3 no puede invocar
     * closures pasados por contexto, así que precomputamos todo aquí.
     *
     * @return array{when: string, partner: string, type: string, detail: string}
     */
    public function renderableRow(PartnerEvent $e): array
    {
        $payload = $e->getPayload() ?? [];
        $partner = $e->getPartner();
        $name = trim(($partner->getSurname() ?? '') . ($partner->getName() ? ', ' . $partner->getName() : ''));

        return [
            'when' => $e->getOccurredAt()->format('d/m/Y H:i'),
            'partner' => $name !== '' ? $name : 'Sin nombre',
            'type' => $this->humanType($e->getType(), $payload),
            'detail' => $this->humanDescription($e->getType(), $payload),
        ];
    }
}
