<?php

namespace App\Service\Delivery;

use App\Entity\Basket;

/**
 * Vista de un reparto semanal. Lo que la pantalla y el PDF necesitan
 * para pintar la lista del viernes: socios agrupados por modalidad,
 * conteos por grupo y catálogo de grupos de recogida.
 *
 * Es un DTO inmutable. Lo construye WeeklyBasketGenerator.
 */
final class WeeklyDeliveryReport
{
    public function __construct(
        public readonly Basket $basket,
        /** @var object[] PartnerBasketShare o WeeklyBasket, dependiendo de si ya existe el listado. */
        public readonly array $weekly_partners,
        /** @var object[] */
        public readonly array $biweekly_partners,
        /** @var object[] */
        public readonly array $monthly_partners,
        /** @var object[] */
        public readonly array $old_half_basket_partners,
        /** @var object[] */
        public readonly array $only_egg_partners,
        /** @var \App\Entity\WeeklyBasketGroup[] */
        public readonly array $weekly_basket_groups,
        public readonly int $basket_weekly_partners_amount,
        public readonly int $basket_biweekly_partners_amount,
        public readonly int $basket_monthly_partners_amount,
        public readonly int $basket_old_half_basket_partners_amount,
        public readonly int $basket_only_egg_partners_amount,
    ) {
    }

    /**
     * Devuelve las claves esperadas por las plantillas legacy. Útil para
     * pasarlo al render con un único spread (...$report->toTemplateContext()).
     *
     * @return array<string, mixed>
     */
    public function toTemplateContext(): array
    {
        return [
            'basket' => $this->basket,
            'weekly_partners' => $this->weekly_partners,
            'biweekly_partners' => $this->biweekly_partners,
            'monthly_partners' => $this->monthly_partners,
            'old_half_basket_partners' => $this->old_half_basket_partners,
            'only_egg_partners' => $this->only_egg_partners,
            'weekly_basket_groups' => $this->weekly_basket_groups,
            'basket_weekly_partners_amount' => $this->basket_weekly_partners_amount,
            'basket_biweekly_partners_amount' => $this->basket_biweekly_partners_amount,
            'basket_monthly_partners_amount' => $this->basket_monthly_partners_amount,
            'basket_old_half_basket_partners_amount' => $this->basket_old_half_basket_partners_amount,
            'basket_only_egg_partners_amount' => $this->basket_only_egg_partners_amount,
        ];
    }
}
