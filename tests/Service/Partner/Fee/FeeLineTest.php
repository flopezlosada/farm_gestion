<?php

namespace App\Tests\Service\Partner\Fee;

use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\PartnerBasketShare;
use App\Service\Partner\Fee\FeeLine;
use App\Service\Partner\Fee\FeeLinePart;
use PHPUnit\Framework\TestCase;

/**
 * La cuenta de cada parte de una cesta en el recibo: cestas × tarifa, docenas ×
 * tarifa de la docena, repartos × precio del reparto. Sólo cuando el importe sale
 * tal cual de esa cuenta.
 */
class FeeLineTest extends TestCase
{
    /**
     * Una cesta de socix con la modalidad, el número de cestas y las docenas dadas.
     *
     * @param int      $basketShareId Uno de BasketShare::ID_*.
     * @param int|null $eggAmountId   El id del catálogo de huevos (docenas = id × 0,5), o null sin huevos.
     */
    private static function share(int $basketShareId, int $amount, ?int $eggAmountId = null): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        $basketShare->setId($basketShareId);
        $share = new PartnerBasketShare();
        $share->setBasketShare($basketShare);
        $share->setAmount($amount);
        if ($eggAmountId !== null) {
            $eggs = new EggAmount();
            (new \ReflectionProperty(EggAmount::class, 'id'))->setValue($eggs, $eggAmountId);
            $share->setEggAmount($eggs);
        }

        return $share;
    }

    /**
     * Las partes de una línea como «Nombre: cantidad × precio = total» para comparar de un vistazo.
     *
     * @return list<string>
     */
    private static function calc(FeeLine $line): array
    {
        return array_map(
            static fn (FeeLinePart $p): string => $p->name . ': ' . ($p->isMultiplied() ? $p->getQuantityLabel() . ' × ' . $p->getUnitPrice() . ' = ' : '') . $p->getTotal(),
            $line->getParts(),
        );
    }

    public function testTresMensualesConTransporteSeCuentanPorCestaYPorReparto(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_MONTHLY, 3), 4500, 0, 1500, 4, 4, []);

        $this->assertSame(['Verdura: 3 cestas × 15.00 = 45.00', 'Transporte: 3 repartos × 5.00 = 15.00'], self::calc($line));
        $this->assertTrue($line->hasSeveralParts());
    }

    public function testUnaQuincenalConDosDocenasCuentaLasDocenas(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_BIWEEKLY, 1, 4), 5500, 2400, 0, 4, 4, []);

        $this->assertSame(['Verdura: 1 cesta × 55.00 = 55.00', 'Huevos: 2 docenas × 12.00 = 24.00'], self::calc($line));
    }

    public function testMediaDocenaSeEscribeConDecimal(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_WEEKLY, 1, 1), 9000, 600, 0, 4, 4, []);

        $this->assertSame('Huevos: 0,5 docenas × 12.00 = 6.00', self::calc($line)[1]);
    }

    /** Media cesta del mes va prorrateada: la cuenta limpia mentiría, sólo se da el importe. */
    public function testParteDelMesNoSeExplicaConUnaMultiplicacion(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_BIWEEKLY, 1, 2), 2750, 600, 0, 2, 4, []);

        $this->assertSame(['Verdura: 27.50', 'Huevos: 6.00'], self::calc($line));
    }

    public function testConSemanasGratisTampocoSeMultiplica(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_WEEKLY, 1), 6750, 0, 0, 4, 4, [], 1);

        $this->assertSame(['Verdura: 67.50'], self::calc($line));
        $this->assertFalse($line->hasSeveralParts());
    }

    /** Una cesta entera sólo de verdura: una parte, nada que desglosar. */
    public function testSoloVerduraEsUnaParte(): void
    {
        $line = new FeeLine(self::share(BasketShare::ID_BIWEEKLY, 1), 5500, 0, 0, 4, 4, []);

        $this->assertFalse($line->hasSeveralParts());
    }

    /** Qué parte del mes paga, en palabras: semanas, medio mes de un alta o nada este mes. */
    public function testLaParteDelMesSeDiceEnPalabras(): void
    {
        $weekly = self::share(BasketShare::ID_WEEKLY, 1);

        $this->assertSame('2 de 4 semanas', (new FeeLine($weekly, 5000, 0, 0, 2, 4, []))->getPartLabel());
        $this->assertSame('medio mes', (new FeeLine($weekly, 5000, 0, 0, 1, 2, [], halfMonth: true))->getPartLabel());
        $this->assertSame('este mes no recibe cesta', (new FeeLine(self::share(BasketShare::ID_MONTHLY, 1), 0, 0, 0, 0, 1, []))->getPartLabel());
    }
}
