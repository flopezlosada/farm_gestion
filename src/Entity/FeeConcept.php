<?php

namespace App\Entity;

/**
 * Lo que tiene un precio base en las cuotas: la cesta de verdura de cada modalidad
 * y la docena de huevos de cada frecuencia.
 *
 * Cada compartida tiene su propio precio y no la mitad de su modalidad: la semanal
 * compartida se cobra 55 €, no 50, y la quincenal compartida no se cobra igual en
 * todas partes. Con un precio propio, lo que se cobra es lo que pone la tarifa, sin
 * una regla escondida. «Solo huevos» no tiene concepto: no lleva verdura.
 */
enum FeeConcept: string
{
    case VegWeekly = 'veg_weekly';
    case VegWeeklyShared = 'veg_weekly_shared';
    case VegBiweekly = 'veg_biweekly';
    case VegBiweeklyShared = 'veg_biweekly_shared';
    case VegMonthly = 'veg_monthly';
    case VegMonthlyShared = 'veg_monthly_shared';
    case EggWeekly = 'egg_weekly';
    case EggBiweekly = 'egg_biweekly';
    case EggMonthly = 'egg_monthly';

    /** El nombre que se ve en pantalla. */
    public function label(): string
    {
        return match ($this) {
            self::VegWeekly => 'Cesta semanal',
            self::VegWeeklyShared => 'Cesta semanal compartida',
            self::VegBiweekly => 'Cesta quincenal',
            self::VegBiweeklyShared => 'Cesta quincenal compartida',
            self::VegMonthly => 'Cesta mensual',
            self::VegMonthlyShared => 'Cesta mensual compartida',
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
            : ($this->isShared() ? 'Al mes, lo que paga cada hogar.' : 'Al mes, por cesta.');
    }

    /**
     * Los repartos de un mes normal: cuatro la semanal, dos la quincenal y uno la
     * mensual, también si es compartida. Es la cuenta con la que se acuerdan los precios
     * por reparto (el transporte), igual en los meses de cinco viernes: la cuota es
     * mensual.
     */
    public function deliveriesPerMonth(): int
    {
        return match ($this) {
            self::VegWeekly, self::VegWeeklyShared, self::EggWeekly => 4,
            self::VegBiweekly, self::VegBiweeklyShared, self::EggBiweekly => 2,
            self::VegMonthly, self::VegMonthlyShared, self::EggMonthly => 1,
        };
    }

    /** Si es de huevos (si no, de verdura). */
    public function isEgg(): bool
    {
        return \in_array($this, [self::EggWeekly, self::EggBiweekly, self::EggMonthly], true);
    }

    /** Si es la verdura de una modalidad compartida (cada hogar paga este precio). */
    public function isShared(): bool
    {
        return \in_array($this, [self::VegWeeklyShared, self::VegBiweeklyShared, self::VegMonthlyShared], true);
    }

    /**
     * Los de verdura y los de huevos, en el orden en que se enseñan.
     *
     * @return array{veg: list<self>, egg: list<self>}
     */
    public static function grouped(): array
    {
        return [
            'veg' => [self::VegWeekly, self::VegWeeklyShared, self::VegBiweekly, self::VegBiweeklyShared, self::VegMonthly, self::VegMonthlyShared],
            'egg' => [self::EggWeekly, self::EggBiweekly, self::EggMonthly],
        ];
    }

    /** El concepto de la verdura de una modalidad. Null para «solo huevos», que no lleva verdura. */
    public static function forBasketShare(BasketShare $basketShare): ?self
    {
        return match ($basketShare->getId()) {
            BasketShare::ID_WEEKLY => self::VegWeekly,
            BasketShare::ID_WEEKLY_SHARED => self::VegWeeklyShared,
            BasketShare::ID_BIWEEKLY => self::VegBiweekly,
            BasketShare::ID_BIWEEKLY_SHARED => self::VegBiweeklyShared,
            BasketShare::ID_MONTHLY => self::VegMonthly,
            BasketShare::ID_MONTHLY_SHARED => self::VegMonthlyShared,
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
