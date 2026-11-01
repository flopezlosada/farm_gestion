<?php

namespace App\Entity;

/**
 * Lo que tiene un precio base en las cuotas: la cesta de verdura de cada modalidad,
 * la docena de huevos de cada frecuencia, y las cuotas de socix de cada hogar: la de
 * inscripción (una vez, al empezar) y la de mantenimiento (cada seis meses).
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
    case MembershipSignup = 'membership_signup';
    case MembershipUpkeep = 'membership_upkeep';

    /** Los meses en que se cobra el mantenimiento: junio y diciembre. */
    public const UPKEEP_MONTHS = [6, 12];

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
            self::MembershipSignup => 'Cuota de inscripción',
            self::MembershipUpkeep => 'Cuota de mantenimiento',
        };
    }

    /** Cómo se aplica el precio: lo que hay que saber para escribirlo bien. */
    public function hint(): string
    {
        return match (true) {
            $this === self::MembershipSignup => 'Una vez por hogar, el mes de su primera cesta.',
            $this === self::MembershipUpkeep => 'Por hogar, en junio y en diciembre.',
            $this->isEgg() => 'Al mes, por cada docena.',
            $this->isShared() => 'Al mes, lo que paga cada hogar.',
            default => 'Al mes, por cesta.',
        };
    }

    /** El bloque en que se enseña: «veg», «egg» o «membership». */
    public function group(): string
    {
        return match (true) {
            $this->isMembership() => 'membership',
            $this->isEgg() => 'egg',
            default => 'veg',
        };
    }

    /** Si es una cuota de socix (inscripción o mantenimiento) y no de cesta. */
    public function isMembership(): bool
    {
        return \in_array($this, [self::MembershipSignup, self::MembershipUpkeep], true);
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
            self::MembershipSignup, self::MembershipUpkeep => throw new \LogicException('Una cuota de socix no va por repartos.'),
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
     * Los de verdura, los de huevos y los de socix, en el orden en que se enseñan.
     *
     * @return array{veg: list<self>, egg: list<self>, membership: list<self>}
     */
    public static function grouped(): array
    {
        return [
            'veg' => [self::VegWeekly, self::VegWeeklyShared, self::VegBiweekly, self::VegBiweeklyShared, self::VegMonthly, self::VegMonthlyShared],
            'egg' => [self::EggWeekly, self::EggBiweekly, self::EggMonthly],
            'membership' => [self::MembershipSignup, self::MembershipUpkeep],
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
