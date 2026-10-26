<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\FlatRemittance;
use PHPUnit\Framework\TestCase;

/**
 * Una derrama o una cuota de socix: el mismo importe a toda una base, sólo a quien se
 * puede domiciliar, con una referencia que no choca con la del recibo de cesta.
 */
class FlatRemittanceTest extends TestCase
{
    private static function partner(string $name, ?string $mandate = null): Partner
    {
        $partner = (new Partner())->setname($name)->setSurname('López García');
        if ($mandate !== null) {
            $partner->setIban('ES91 2100 0418 4502 0005 1332')->setMandateReference($mandate)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        }

        return $partner;
    }

    /**
     * Un recibo por persona con mandato, aunque venga dos veces en la base; quien no
     * tiene mandato, o lo tiene a medias (sin fecha de firma), no entra.
     */
    public function testUnReciboIgualPorCadaPersonaDomiciliable(): void
    {
        $ana = self::partner('Ana', 'CUOTA MENSUAL 200');
        $bea = self::partner('Bea');
        $carla = self::partner('Carla', 'CUOTA MENSUAL 201');
        $dani = self::partner('Dani', 'CUOTA MENSUAL 202')->setMandateSignedOn(null);

        $remittance = (new FlatRemittance())->build(
            Remittance::KIND_OTHER,
            new \DateTimeImmutable('2026-11-01'),
            'Derrama tractor',
            '20.00',
            [$ana, $bea, $carla, $ana, $dani],
            null,
        );

        $lines = $remittance->getLines()->toArray();
        $this->assertSame([$ana, $carla], array_map(static fn (RemittanceLine $l): ?Partner => $l->getPartner(), $lines));
        $this->assertSame(['20.00', '20.00'], array_map(static fn (RemittanceLine $l): string => $l->getAmount(), $lines));
        $this->assertSame('40.00', $remittance->getTotal());
        $this->assertSame('Derrama tractor', $remittance->getLabel());
        $this->assertSame(Remittance::KIND_OTHER, $remittance->getKind());
        $this->assertSame('ANA LÓPEZ GARCÍA', $lines[0]->getDebtorName());
    }

    /**
     * La referencia del adeudo lleva el mes y una marca del tipo: el mismo mandato
     * cobra ese mes la cesta con «… 202611», y dos adeudos iguales confunden al banco.
     */
    public function testLaReferenciaNoChocaConLaDeLaCesta(): void
    {
        $ana = self::partner('Ana', 'CUOTA MENSUAL 200');
        $build = static fn (string $kind): string => (new FlatRemittance())
            ->build($kind, new \DateTimeImmutable('2026-11-01'), 'x', '10.00', [$ana], null)
            ->getLines()->first()->getDebitReference();

        $this->assertSame('CUOTA MENSUAL 200 202611EX', $build(Remittance::KIND_OTHER));
        $this->assertSame('CUOTA MENSUAL 200 202611CS', $build(Remittance::KIND_MEMBERSHIP));
    }

    /** La de cuotas de cesta no es de importe fijo: sale del cálculo de cada ficha. */
    public function testLaDeCuotasDeCestaNoSePreparaAsi(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FlatRemittance())->build(Remittance::KIND_BASKET, new \DateTimeImmutable('2026-11-01'), 'x', '10.00', [], null);
    }
}
