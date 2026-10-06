<?php

namespace App\EventListener;

use App\Service\Accounting\Invoice\InvoiceReadQueue;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lee las facturas recién subidas DESPUÉS de haber contestado al navegador.
 *
 * Symfony dispara `kernel.terminate` cuando la respuesta ya ha salido (bajo php-fpm,
 * tras `fastcgi_finish_request()`), así que quien sube no espera a la lectura y la
 * petición no se arriesga al tiempo máximo del hosting. Al volver a la bandeja, lo
 * normal es encontrarlas ya leídas.
 *
 * Sólo actúa si la petición marcó facturas para leer: el atributo lo pone el
 * controlador al guardarlas.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
class InvoiceReadListener
{
    /** Atributo de la petición con los ids de las facturas a leer. */
    public const ATTRIBUTE = '_read_invoices';

    public function __construct(private readonly InvoiceReadQueue $queue)
    {
    }

    /**
     * @param TerminateEvent $event Evento de fin de petición.
     */
    public function __invoke(TerminateEvent $event): void
    {
        $ids = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_array($ids) || $ids === []) {
            return;
        }

        // Ya no hay nadie esperando al otro lado; la reserva de cada factura impide
        // que esta lectura se pise con la del planificador.
        @set_time_limit(0);

        $this->queue->process(\count($ids), array_values(array_map('intval', $ids)));
    }
}
