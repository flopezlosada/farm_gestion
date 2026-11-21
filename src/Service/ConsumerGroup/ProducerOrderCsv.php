<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV del pedido AGREGADO que se le pasa a un productor: una fila por producto con
 * la cantidad total pedida y el subtotal.
 *
 * La cantidad es la TOTAL —lo de las socias más lo que la asociación encarga para el
 * local—, sin desglosar: al productor le da igual de quién es cada caja. El precio es
 * el SUYO, sin el incremento de gestión de la asociación, que no es asunto suyo
 * ({@see \App\Entity\ConsumerGroupRoundItem::getPriceForProducer()}).
 *
 * Con un productor se limita a sus productos; sin él, incluye todos los del pedido.
 */
class ProducerOrderCsv
{
    public function __construct(private readonly OrderAggregator $aggregator)
    {
    }

    /**
     * Respuesta de descarga con el CSV del pedido, entero o de un solo productor.
     */
    public function response(ConsumerGroupRound $round, ?Producer $producer = null): StreamedResponse
    {
        $aggregate = $this->aggregator->aggregate($round, $producer);

        $response = new StreamedResponse(function () use ($aggregate): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel.
            fputcsv($out, ['Producto', 'Unidad', 'Cantidad total', 'Precio', 'Subtotal']);
            foreach ($aggregate['byItem'] as $line) {
                $item = $line['item'];
                fputcsv($out, [
                    $item->getName(),
                    $item->getUnit(),
                    number_format($line['totalQuantity'], 2, ',', ''),
                    number_format((float) $item->getPriceForProducer(), 2, ',', ''),
                    number_format($line['producerSubtotal'], 2, ',', ''),
                ]);
            }
            fputcsv($out, ['', '', '', 'Total', number_format($aggregate['producerTotal'], 2, ',', '')]);
            fclose($out);
        });

        $filename = sprintf('pedido-%s%s-%s.csv', $round->getId(), $producer ? '-'.$producer->getId() : '', (new \DateTime())->format('Ymd'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
