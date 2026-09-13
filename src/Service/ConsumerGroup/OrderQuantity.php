<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRoundItem;

/**
 * Cómo se convierte lo que llega de un formulario en una cantidad válida de un
 * producto del grupo de consumo. Función pura, sin estado ni BBDD.
 *
 * Vive aparte porque hay DOS sitios que piden cantidades del mismo producto —lo
 * que apunta cada socia y lo que la asociación encarga para el local— y el salto
 * tiene que ser el mismo en los dos. Cuando cada pantalla normalizaba por su
 * cuenta, una de ellas reutilizó el normalizador de los PRECIOS —donde los
 * decimales sí valen— y se acabaron guardando líneas de «0,03 garrafas de 5 L»,
 * que no significan nada para quien las pide y llegan así al pedido del
 * productor.
 */
final class OrderQuantity
{
    /**
     * Ajusta un valor recién llegado del formulario al salto de ese producto:
     * unidades enteras, o medias si el producto las admite
     * ({@see \App\Entity\ConsumerGroupProduct::orderStep()}).
     *
     * Acepta la coma decimal porque es como se teclea aquí, y AJUSTA AL SALTO en
     * vez de rechazar: del campo no puede salir «1,3», pero sí de un formulario
     * manipulado o de un pegado, y ahí es mejor guardar 1,5 que perder el pedido
     * entero. Lo negativo y lo que no es número valen cero, que es «no pido
     * nada».
     *
     * @param mixed                  $value cantidad tal cual llega del formulario
     *                                      (puede ser cualquier cosa: el
     *                                      formulario lo manda quien quiera)
     * @param ConsumerGroupRoundItem $item  el producto pedido, que dice el salto
     *
     * @return string cantidad, como cadena apta para guardar
     */
    public static function forItem(mixed $value, ConsumerGroupRoundItem $item): string
    {
        if (!is_scalar($value)) {
            return '0';
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized) || (float) $normalized <= 0) {
            return '0';
        }

        // Sin producto detrás (catálogo borrado) se cae al salto más estricto: la
        // unidad entera. Es lo que no puede sorprender a nadie.
        $step = $item->getProduct()?->orderStep() ?? 1.0;
        $rounded = round((float) $normalized / $step) * $step;

        // Se formatea sin decimales sobrantes: «2» y «2.5», no «2.00».
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
