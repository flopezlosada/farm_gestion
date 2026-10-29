<?php

namespace App\Service\Delivery;

use App\Entity\BasketComponent;
use App\Entity\WeeklyBasket;

/**
 * Qué se lleva un socix en un reparto, tal como se le nombra en los avisos.
 *
 * Existe porque en la asociación "cesta" a secas se entiende como verdura: a
 * quien sólo recoge huevos, un correo que dice "tu cesta de esta semana" le hace
 * pensar que le ha caído una cesta que no pidió. Por eso los avisos dicen siempre
 * de qué es la cesta.
 *
 * Se deriva de las líneas materializadas de la entrega y no de la modalidad: un
 * semanal con huevos quincenales recoge huevos una semana de cada dos, y quien
 * tiene cesta mensual con huevos semanales recoge sólo huevos tres semanas de
 * cada cuatro. La modalidad no dice qué hay ESE día; las líneas sí.
 */
enum PickupContents
{
    case Vegetables;
    case Eggs;
    case VegetablesAndEggs;

    /**
     * Lo que se recoge con unas cantidades por componente. Sin huevos se da por
     * hecho que es verdura, que es lo que la cesta ha sido siempre: así una
     * entrega sin líneas (no debería haberla) se nombra como hasta ahora.
     *
     * @param array<int, float> $amounts [basketComponentId => cantidad]
     * @return self Lo que se recoge.
     */
    public static function fromAmounts(array $amounts): self
    {
        $vegetables = ($amounts[BasketComponent::ID_VEGETABLES] ?? 0.0) > 0;
        $eggs = ($amounts[BasketComponent::ID_EGGS] ?? 0.0) > 0;

        return match (true) {
            $eggs && $vegetables => self::VegetablesAndEggs,
            $eggs => self::Eggs,
            default => self::Vegetables,
        };
    }

    /**
     * Lo que recoge cada socix, sumando todas sus entregas de la lista.
     *
     * Por socix y no por entrega porque los avisos son uno por socix: quien tiene
     * una cesta extra puntual el mismo día sale dos veces, y el único aviso que
     * recibe tiene que contar lo de las dos.
     *
     * @param WeeklyBasket[]                $weeklyBaskets    Entregas de un mismo reparto.
     * @param array<int, array<int, float>> $componentAmounts [weeklyBasketId => [basketComponentId => cantidad]]
     * @return array<int, self> [partnerId => lo que recoge]
     */
    public static function byPartner(array $weeklyBaskets, array $componentAmounts): array
    {
        $summed = [];
        foreach ($weeklyBaskets as $weeklyBasket) {
            $partnerId = $weeklyBasket->getPartner()?->getId();
            if ($partnerId === null) {
                continue;
            }

            $summed[$partnerId] ??= [];
            foreach ($componentAmounts[$weeklyBasket->getId()] ?? [] as $componentId => $amount) {
                $summed[$partnerId][$componentId] = ($summed[$partnerId][$componentId] ?? 0.0) + $amount;
            }
        }

        return array_map(self::fromAmounts(...), $summed);
    }

    /**
     * El complemento que va detrás de "cesta" en los textos: "tu cesta de huevos",
     * "tu cesta quincenal de verduras y huevos".
     *
     * @return string El complemento, empezando por "de".
     */
    public function label(): string
    {
        return match ($this) {
            self::Vegetables => 'de verduras',
            self::Eggs => 'de huevos',
            self::VegetablesAndEggs => 'de verduras y huevos',
        };
    }
}
