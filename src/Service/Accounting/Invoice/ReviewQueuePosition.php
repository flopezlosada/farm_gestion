<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;

/**
 * Dónde está una factura dentro de las que esperan revisión, para ir de una a otra.
 *
 * La cola va por orden de llegada (id). La factura puede no estar en ella (recién
 * anotada, o aún sin leer): entonces sus vecinas son las de antes y después de su id,
 * y no tiene número de puesto.
 */
final class ReviewQueuePosition
{
    /**
     * @param ReceivedInvoice|null $previous La anterior por revisar.
     * @param ReceivedInvoice|null $next     La siguiente por revisar.
     * @param int|null             $position Su puesto en la cola (desde 1), si está en ella.
     * @param int                  $total    Cuántas hay en la cola.
     */
    private function __construct(
        public readonly ?ReceivedInvoice $previous,
        public readonly ?ReceivedInvoice $next,
        public readonly ?int $position,
        public readonly int $total,
    ) {
    }

    /**
     * El puesto de una factura en la cola.
     *
     * @param ReceivedInvoice       $current La factura que se tiene delante.
     * @param list<ReceivedInvoice> $queue   Las que esperan revisión, por id ascendente.
     */
    public static function of(ReceivedInvoice $current, array $queue): self
    {
        $id = $current->getId();
        $before = array_values(array_filter($queue, static fn (ReceivedInvoice $i): bool => $i->getId() < $id));
        $after = array_values(array_filter($queue, static fn (ReceivedInvoice $i): bool => $i->getId() > $id));
        $inQueue = \count($before) + \count($after) < \count($queue);

        return new self(
            $before === [] ? null : $before[\count($before) - 1],
            $after[0] ?? null,
            $inQueue ? \count($before) + 1 : null,
            \count($queue),
        );
    }
}
