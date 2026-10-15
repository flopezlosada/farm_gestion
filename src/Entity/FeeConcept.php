<?php

namespace App\Entity;

/**
 * Lo que tiene un precio base en las cuotas: la cesta de verdura de cada modalidad
 * y la docena de huevos de cada frecuencia.
 *
 * Las modalidades compartidas no tienen concepto propio: pagan la mitad de su
 * modalidad, así que no hay un segundo precio que pueda quedarse desfasado. Tampoco
 * «solo huevos», que no lleva verdura.
 */
enum FeeConcept: string
{
    case VegWeekly = 'veg_weekly';
    case VegBiweekly = 'veg_biweekly';
    case VegMonthly = 'veg_monthly';
    case EggWeekly = 'egg_weekly';
    case EggBiweekly = 'egg_biweekly';
    case EggMonthly = 'egg_monthly';

    /** El nombre que se ve en pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::VegWeekly => 'Cesta semanal',
            self::VegBiweekly => 'Cesta quincenal',
            self::VegMonthly => 'Cesta mensual',
            self::EggWeekly => 'Docena semanal',
            self::EggBiweekly => 'Docena quincenal',
            self::EggMonthly => 'Docena mensual',
        };
    }

    /** Cómo se aplica el precio: lo que hay que saber para escribirlo bien. */
    public function hint(): string
    {
        return $this->isEgg()
            ? 'Al mes, por cada docena.'
            : 'Al mes, por cesta. La compartida paga la mitad.';
    }

    /** Si es de huevos (si no, de verdura). */
    public function isEgg(): bool
    {
        return \in_array($this, [self::EggWeekly, self::EggBiweekly, self::EggMonthly], true);
    }

    /**
     * Los de verdura y los de huevos, en el orden en que se enseñan.
     *
     * @return array{veg: list<self>, egg: list<self>}
     */
    public static function grouped(): array
    {
        return [
            'veg' => [self::VegWeekly, self::VegBiweekly, self::VegMonthly],
            'egg' => [self::EggWeekly, self::EggBiweekly, self::EggMonthly],
        ];
    }

    /**
     * El concepto de la verdura de una modalidad: la compartida, el de su modalidad
     * completa. Null para «solo huevos», que no lleva verdura.
     */
    public static function forBasketShare(BasketShare $basketShare): ?self
    {
        return match ($basketShare->getId()) {
            BasketShare::ID_WEEKLY, BasketShare::ID_WEEKLY_SHARED => self::VegWeekly,
            BasketShare::ID_BIWEEKLY, BasketShare::ID_BIWEEKLY_SHARED => self::VegBiweekly,
            BasketShare::ID_MONTHLY, BasketShare::ID_MONTHLY_SHARED => self::VegMonthly,
            default => null,
        };
    }

    /** El concepto de la docena de huevos de una frecuencia. */
    public static function forEggPeriod(EggPeriod $eggPeriod): ?self
    {
        return match ($eggPeriod->getId()) {
            EggPeriod::ID_WEEKLY => self::EggWeekly,
            EggPeriod::ID_BIWEEKLY => self::EggBiweekly,
            EggPeriod::ID_MONTHLY => self::EggMonthly,
            default => null,
        };
    }
}
