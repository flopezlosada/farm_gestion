<?php

namespace App\Tests\Service\Delivery;

use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Service\Delivery\PickupContents;
use PHPUnit\Framework\TestCase;

/**
 * Qué se le dice a cada socix que recoge. Lo que se protege es que a quien sólo
 * se lleva huevos no se le hable de "cesta" a secas, que en la asociación se
 * entiende como verdura, y que eso salga de lo que hay ESE día y no de la
 * modalidad.
 */
class PickupContentsTest extends TestCase
{
    /**
     * @dataProvider cantidades
     *
     * @param array<int, float> $amounts [basketComponentId => cantidad]
     */
    public function testLoQueSeRecogeSaleDeLasCantidades(array $amounts, PickupContents $esperado): void
    {
        $this->assertSame($esperado, PickupContents::fromAmounts($amounts));
    }

    /** @return iterable<string, array{array<int, float>, PickupContents}> */
    public static function cantidades(): iterable
    {
        yield 'sólo verdura' => [[BasketComponent::ID_VEGETABLES => 1.0], PickupContents::Vegetables];
        yield 'sólo huevos' => [[BasketComponent::ID_EGGS => 1.5], PickupContents::Eggs];
        yield 'media cesta y huevos' => [[BasketComponent::ID_VEGETABLES => 0.5, BasketComponent::ID_EGGS => 1.0], PickupContents::VegetablesAndEggs];
        // Una línea de huevos a cero no son huevos: es la semana que no le tocan
        // a un semanal con huevos quincenales.
        yield 'huevos a cero' => [[BasketComponent::ID_VEGETABLES => 1.0, BasketComponent::ID_EGGS => 0.0], PickupContents::Vegetables];
        // Sin líneas se nombra como se ha nombrado siempre la cesta.
        yield 'sin líneas' => [[], PickupContents::Vegetables];
    }

    public function testCadaCasoSeNombraConSuComplemento(): void
    {
        $this->assertSame('de verduras', PickupContents::Vegetables->label());
        $this->assertSame('de huevos', PickupContents::Eggs->label());
        $this->assertSame('de verduras y huevos', PickupContents::VegetablesAndEggs->label());
    }

    /**
     * Quien tiene huevos y además una cesta extra puntual el mismo día sale dos
     * veces de la lista y recibe UN aviso: ese aviso tiene que contar las dos
     * cosas, no la que salga primero.
     */
    public function testSumaLasEntregasDelMismoSocix(): void
    {
        $socix = $this->partner(10);
        $otra = $this->partner(11);

        $result = PickupContents::byPartner(
            [$this->weeklyBasket(1, $socix), $this->weeklyBasket(2, $socix), $this->weeklyBasket(3, $otra)],
            [
                1 => [BasketComponent::ID_EGGS => 1.0],
                2 => [BasketComponent::ID_VEGETABLES => 1.0],
                3 => [BasketComponent::ID_EGGS => 0.5],
            ],
        );

        $this->assertSame([10 => PickupContents::VegetablesAndEggs, 11 => PickupContents::Eggs], $result);
    }

    /**
     * Una entrega sin líneas sigue dando entrada a su socix, nombrada como
     * verdura; si no, el aviso se quedaría sin qué decir.
     */
    public function testUnaEntregaSinLineasTambienCuenta(): void
    {
        $result = PickupContents::byPartner([$this->weeklyBasket(1, $this->partner(12))], []);

        $this->assertSame([12 => PickupContents::Vegetables], $result);
    }

    /**
     * Una entrega sin socix no tiene a quién avisar y no entra.
     */
    public function testIgnoraLasEntregasSinSocix(): void
    {
        $this->assertSame([], PickupContents::byPartner([$this->weeklyBasket(1, null)], [1 => [BasketComponent::ID_EGGS => 1.0]]));
    }

    /**
     * @param int $id Identificador del socix.
     */
    private function partner(int $id): Partner
    {
        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn($id);

        return $partner;
    }

    /**
     * @param int          $id      Identificador de la entrega.
     * @param Partner|null $partner Socix que la recoge.
     */
    private function weeklyBasket(int $id, ?Partner $partner): WeeklyBasket
    {
        $weeklyBasket = $this->createMock(WeeklyBasket::class);
        $weeklyBasket->method('getId')->willReturn($id);
        $weeklyBasket->method('getPartner')->willReturn($partner);

        return $weeklyBasket;
    }
}
