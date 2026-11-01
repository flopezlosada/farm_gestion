<?php

namespace App\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\EggPeriod;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\IssuedInvoice;
use App\Entity\TransportRate;
use App\Entity\WeeklyBasketGroup;

/**
 * Todos los precios base con sus fechas, para preguntar qué valía cada cosa un día
 * dado. No consulta la base: recibe los precios ya cargados (son unas pocas filas),
 * así que se puede preguntar por cientos de cestas sin una consulta por cada una.
 *
 * Si un concepto no tiene precio en una fecha, la respuesta es null y NO cero: sin
 * precio no se puede calcular una cuota, y un cero cobraría de menos sin avisar.
 *
 * El transporte es distinto: sólo lo pagan los grupos de recogida que tienen precio
 * ({@see TransportRate}), así que un grupo sin precio paga cero y no falta nada.
 */
final class FeeSchedule
{
    /** @var array<string, list<FeeRate>> Por concepto, el más antiguo primero. */
    private array $byConcept = [];

    /** @var array<int, list<TransportRate>> Por grupo de recogida, el más antiguo primero. */
    private array $transportByGroup = [];

    /**
     * @param iterable<FeeRate>       $rates
     * @param iterable<TransportRate> $transportRates
     */
    public function __construct(iterable $rates, iterable $transportRates = [])
    {
        foreach ($rates as $rate) {
            $this->byConcept[$rate->getConcept()->value][] = $rate;
        }
        foreach ($transportRates as $rate) {
            $this->transportByGroup[self::groupKey($rate->getPickupGroup())][] = $rate;
        }
        $this->byConcept = array_map(self::oldestFirst(...), $this->byConcept);
        $this->transportByGroup = array_map(self::oldestFirst(...), $this->transportByGroup);
    }

    /** El precio que vale un día: el último que empezó ese día o antes. */
    public function rateOn(FeeConcept $concept, \DateTimeInterface $day): ?FeeRate
    {
        return self::validOn($this->byConcept[$concept->value] ?? [], $day);
    }

    /** El siguiente precio ya fijado que aún no ha empezado ese día. */
    public function nextAfter(FeeConcept $concept, \DateTimeInterface $day): ?FeeRate
    {
        return self::firstAfter($this->byConcept[$concept->value] ?? [], $day);
    }

    /**
     * Todos los precios de un concepto, el más reciente primero.
     *
     * @return list<FeeRate>
     */
    public function history(FeeConcept $concept): array
    {
        return array_reverse($this->byConcept[$concept->value] ?? []);
    }

    /**
     * Los conceptos que no tienen precio un día: lo que falta para poder calcular
     * las cuotas.
     *
     * @return list<FeeConcept>
     */
    public function missingOn(\DateTimeInterface $day): array
    {
        return array_values(array_filter(FeeConcept::cases(), fn (FeeConcept $c): bool => $this->rateOn($c, $day) === null));
    }

    /**
     * Lo que cuesta al mes una cesta de verdura de una modalidad un día: el precio de
     * su modalidad (la compartida tiene el suyo). «Solo huevos» no lleva verdura: cero.
     *
     * @return string|null Euros, «27.50»; null si su modalidad no tiene precio ese día.
     */
    public function vegMonthPrice(BasketShare $basketShare, \DateTimeInterface $day): ?string
    {
        $concept = FeeConcept::forBasketShare($basketShare);
        if ($concept === null) {
            return '0.00';
        }

        return $this->rateOn($concept, $day)?->getPrice();
    }

    /**
     * Lo que cuesta al mes una docena de huevos de una frecuencia un día.
     *
     * @return string|null Euros; null si esa frecuencia no tiene precio ese día.
     */
    public function eggDozenMonthPrice(EggPeriod $eggPeriod, \DateTimeInterface $day): ?string
    {
        $concept = FeeConcept::forEggPeriod($eggPeriod);

        return $concept === null ? null : $this->rateOn($concept, $day)?->getPrice();
    }

    /** El precio de transporte de un grupo que vale un día; null si ese día no pagaba. */
    public function transportRateOn(WeeklyBasketGroup $group, \DateTimeInterface $day): ?TransportRate
    {
        return self::validOn($this->transportByGroup[self::groupKey($group)] ?? [], $day);
    }

    /** El siguiente precio de transporte de un grupo ya fijado que aún no ha empezado. */
    public function transportNextAfter(WeeklyBasketGroup $group, \DateTimeInterface $day): ?TransportRate
    {
        return self::firstAfter($this->transportByGroup[self::groupKey($group)] ?? [], $day);
    }

    /**
     * Los grupos de recogida que tienen o han tenido precio de transporte.
     *
     * @return list<WeeklyBasketGroup>
     */
    public function transportGroups(): array
    {
        return array_values(array_map(static fn (array $rates): WeeklyBasketGroup => $rates[0]->getPickupGroup(), $this->transportByGroup));
    }

    /**
     * Todos los precios de transporte, el más reciente primero.
     *
     * @return list<TransportRate>
     */
    public function transportHistory(): array
    {
        return array_reverse(self::oldestFirst(array_merge([], ...array_values($this->transportByGroup))));
    }

    /**
     * Lo que cuesta al mes llevar una cesta de una modalidad a un grupo un día: el
     * precio por reparto del grupo por los repartos de un mes de su modalidad. Cero si
     * el grupo no paga transporte o si no lleva verdura («solo huevos» no es una cesta).
     *
     * @return string Euros, «10.00».
     */
    public function transportMonthPrice(?WeeklyBasketGroup $group, ?BasketShare $basketShare, \DateTimeInterface $day): string
    {
        $concept = $basketShare !== null ? FeeConcept::forBasketShare($basketShare) : null;
        $rate = $group !== null && $concept !== null ? $this->transportRateOn($group, $day) : null;

        return $rate === null ? '0.00' : IssuedInvoice::fromCents(IssuedInvoice::toCents($rate->getPricePerDelivery()) * $concept->deliveriesPerMonth());
    }

    /**
     * El último precio que empezó ese día o antes.
     *
     * @template T of FeeRate|TransportRate
     *
     * @param list<T> $rates El más antiguo primero.
     *
     * @return T|null
     */
    private static function validOn(array $rates, \DateTimeInterface $day): FeeRate|TransportRate|null
    {
        $day = self::day($day);
        $valid = array_filter($rates, static fn (FeeRate|TransportRate $r): bool => $r->getValidFrom() <= $day);

        return $valid === [] ? null : end($valid);
    }

    /**
     * El primer precio que aún no ha empezado ese día.
     *
     * @template T of FeeRate|TransportRate
     *
     * @param list<T> $rates El más antiguo primero.
     *
     * @return T|null
     */
    private static function firstAfter(array $rates, \DateTimeInterface $day): FeeRate|TransportRate|null
    {
        $day = self::day($day);
        foreach ($rates as $rate) {
            if ($rate->getValidFrom() > $day) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * Los precios ordenados por fecha de inicio, el más antiguo primero.
     *
     * @template T of FeeRate|TransportRate
     *
     * @param list<T> $rates
     *
     * @return list<T>
     */
    private static function oldestFirst(array $rates): array
    {
        usort($rates, static fn (FeeRate|TransportRate $a, FeeRate|TransportRate $b): int => $a->getValidFrom() <=> $b->getValidFrom());

        return $rates;
    }

    /** La clave de un grupo: su id, o el objeto si aún no está guardado (en las pruebas). */
    private static function groupKey(WeeklyBasketGroup $group): int
    {
        return $group->getId() ?? spl_object_id($group);
    }

    private static function day(\DateTimeInterface $day): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($day)->setTime(0, 0);
    }
}
