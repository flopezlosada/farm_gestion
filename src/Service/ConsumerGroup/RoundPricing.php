<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\MarkupType;

/**
 * Mantiene el precio final de los productos de un pedido a partir de su desglose:
 * precio del productor + incremento de gestión ({@see Markup}).
 *
 * Reglas:
 *   - El incremento efectivo de un producto es el suyo si lo tiene; si no, el del pedido.
 *   - Un productor autogestionado no lleva incremento: el precio final es el suyo.
 *   - Cambiar el incremento del pedido reprecia los productos que heredan y marca como
 *     «pendientes de revisar» los que tienen incremento propio (no se tocan solos).
 *   - Con socias apuntadas el precio final no se puede cambiar.
 *
 * No persiste: lo hace quien llama.
 */
class RoundPricing
{
    /**
     * Recalcula el precio final de un producto desde su precio de productor y su
     * incremento efectivo. Sin precio de productor (ronda sin desglosar) no hace nada:
     * el precio final es el dato.
     *
     * @throws PriceLocked si hay socias apuntadas y el precio resultante sería distinto
     */
    public function reprice(ConsumerGroupRoundItem $item): void
    {
        if ($item->getProducerPrice() === null) {
            return;
        }

        $newPrice = $this->priceFor($item);
        $this->assertPriceChangeAllowed($item, $newPrice);
        $item->setPrice($newPrice);
    }

    /**
     * Cambia el incremento por defecto del pedido. Los productos que heredan se
     * recalculan; los que tienen incremento propio quedan pendientes de revisar.
     * Todo o nada: si algún precio está bloqueado, no se cambia nada.
     *
     * @throws PriceLocked
     * @throws \InvalidArgumentException si el precio resultante no es válido
     */
    public function changeRoundMarkup(ConsumerGroupRound $round, ?MarkupType $type, ?string $value): void
    {
        $previous = [$round->getMarkupType(), $round->getMarkupValue()];
        if ($type === $previous[0] && self::sameDecimal($value, $previous[1])) {
            return;
        }
        $round->setMarkup($type, $value);

        try {
            foreach ($round->getItems() as $item) {
                if ($item->getOwnMarkup() !== null) {
                    continue;
                }
                $this->assertPriceChangeAllowed($item, $this->priceFor($item));
            }
        } catch (\Throwable $failed) {
            // Todo o nada: el pedido no puede quedarse con un incremento a medio aplicar.
            $round->setMarkup(...$previous);
            throw $failed;
        }

        foreach ($round->getItems() as $item) {
            if ($item->getOwnMarkup() !== null) {
                $item->setMarkupPendingReview(true);
            } else {
                $this->reprice($item);
            }
        }
    }

    /**
     * Fija el desglose de un producto: precio del productor e incremento propio (type y
     * value null = hereda el del pedido), y recalcula el precio final. Si `$reviewed`
     * es true, el producto deja de estar pendiente de revisar.
     *
     * @throws PriceLocked
     */
    public function setBreakdown(ConsumerGroupRoundItem $item, string $producerPrice, ?MarkupType $type, ?string $value, bool $reviewed): void
    {
        $producerCents = Markup::toCents($producerPrice);
        if ($producerCents <= 0) {
            throw new \InvalidArgumentException(sprintf('Pon el precio del productor de «%s»: no puede quedarse a 0.', $item->getName()));
        }
        $item->setProducerPrice(Markup::fromCents($producerCents));
        $item->setOwnMarkup($type, $value);
        $this->reprice($item);
        // Sin incremento propio ya no hay nada que revisar: hereda el del pedido.
        if ($reviewed || $type === null) {
            $item->setMarkupPendingReview(false);
        }
    }

    /**
     * Desglosa un producto antiguo sin cambiar su precio final: el precio del productor
     * es el dato y el incremento sale de la diferencia, en euros.
     *
     * @throws \InvalidArgumentException si el precio del productor no está entre 0 (excluido) y el final
     */
    public function breakDown(ConsumerGroupRoundItem $item, string $producerPrice): void
    {
        $producerCents = Markup::toCents($producerPrice);
        $finalCents = Markup::toCents($item->getPrice());
        if ($producerCents <= 0 || $producerCents > $finalCents) {
            throw new \InvalidArgumentException('El precio del productor tiene que ser mayor que 0 y no superar el precio final.');
        }

        $item->setProducerPrice(Markup::fromCents($producerCents));
        $item->setOwnMarkup(MarkupType::Amount, Markup::fromCents($finalCents - $producerCents));
        $item->setMarkupPendingReview(false);
    }

    /**
     * Productos con incremento propio que siguen pendientes de revisar.
     *
     * @return list<ConsumerGroupRoundItem>
     */
    public function pendingReview(ConsumerGroupRound $round): array
    {
        return array_values(array_filter(
            $round->getItems()->toArray(),
            static fn (ConsumerGroupRoundItem $item): bool => $item->isMarkupPendingReview(),
        ));
    }

    /**
     * Precio final que tendría el producto con el estado actual, sin aplicarlo.
     */
    private function priceFor(ConsumerGroupRoundItem $item): string
    {
        $producerPrice = $item->getProducerPrice();
        if ($producerPrice === null) {
            return $item->getPrice();
        }
        $markup = $item->isSelfManaged() ? null : $item->getEffectiveMarkup();

        return $markup?->applyTo($producerPrice) ?? Markup::fromCents(Markup::toCents($producerPrice));
    }

    /**
     * Con socias apuntadas, el precio final no puede cambiar.
     *
     * @throws PriceLocked
     */
    private function assertPriceChangeAllowed(ConsumerGroupRoundItem $item, string $newPrice): void
    {
        // Primero lo barato: solo si el precio cambia de verdad se miran las líneas
        // (cargarlas cuesta una consulta por producto).
        if (Markup::toCents($newPrice) === Markup::toCents($item->getPrice()) || !$item->hasOrderedQuantity()) {
            return;
        }

        throw new PriceLocked(sprintf(
            'No se puede cambiar el precio de «%s»: ya hay socias apuntadas. Si hace falta cambiarlo de verdad, díselo a quien administra la aplicación.',
            $item->getName(),
        ));
    }

    /**
     * ¿Son iguales dos decimales en string? Ambos null cuentan como iguales.
     */
    private static function sameDecimal(?string $a, ?string $b): bool
    {
        return $a === null || $b === null
            ? $a === $b
            : Markup::toCents($a) === Markup::toCents($b);
    }
}
