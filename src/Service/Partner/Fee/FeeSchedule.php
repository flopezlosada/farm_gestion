<?php

namespace App\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\EggPeriod;
use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\IssuedInvoice;

/**
 * Todos los precios base con sus fechas, para preguntar qué valía cada cosa un día
 * dado. No consulta la base: recibe los precios ya cargados (son unas pocas filas),
 * así que se puede preguntar por cientos de cestas sin una consulta por cada una.
 *
 * Si un concepto no tiene precio en una fecha, la respuesta es null y NO cero: sin
 * precio no se puede calcular una cuota, y un cero cobraría de menos sin avisar.
 */
final class FeeSchedule
{
    /** @var array<string, list<FeeRate>> Por concepto, el más antiguo primero. */
    private array $byConcept = [];

    /**
     * @param iterable<FeeRate> $rates
     */
    public function __construct(iterable $rates)
    {
        foreach ($rates as $rate) {
            $this->byConcept[$rate->getConcept()->value][] = $rate;
        }
        $this->byConcept = array_map(static function (array $list): array {
            usort($list, static fn (FeeRate $a, FeeRate $b): int => $a->getValidFrom() <=> $b->getValidFrom());

            return $list;
        }, $this->byConcept);
    }

    /** El precio que vale un día: el último que empezó ese día o antes. */
    public function rateOn(FeeConcept $concept, \DateTimeInterface $day): ?FeeRate
    {
        $day = self::day($day);
        $valid = array_filter($this->byConcept[$concept->value] ?? [], static fn (FeeRate $r): bool => $r->getValidFrom() <= $day);

        return $valid === [] ? null : end($valid);
    }

    /** El siguiente precio ya fijado que aún no ha empezado ese día. */
    public function nextAfter(FeeConcept $concept, \DateTimeInterface $day): ?FeeRate
    {
        $day = self::day($day);
        foreach ($this->byConcept[$concept->value] ?? [] as $rate) {
            if ($rate->getValidFrom() > $day) {
                return $rate;
            }
        }

        return null;
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
     * su modalidad, la mitad si es compartida. «Solo huevos» no lleva verdura: cero.
     *
     * @return string|null Euros, «27.50»; null si su modalidad no tiene precio ese día.
     */
    public function vegMonthPrice(BasketShare $basketShare, \DateTimeInterface $day): ?string
    {
        $concept = FeeConcept::forBasketShare($basketShare);
        if ($concept === null) {
            return '0.00';
        }
        $rate = $this->rateOn($concept, $day);

        return $rate === null ? null : self::portion($rate->getPrice(), $basketShare->getDeliveredBasketWeight());
    }

    /**
     * La parte de un precio que paga una modalidad según la cesta física que se lleva:
     * la compartida (½) paga la mitad, redondeada al céntimo.
     */
    public static function portion(string $price, float $weight): string
    {
        return IssuedInvoice::fromCents((int) round(IssuedInvoice::toCents($price) * $weight));
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

    private static function day(\DateTimeInterface $day): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($day)->setTime(0, 0);
    }
}
