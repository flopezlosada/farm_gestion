<?php

namespace App\Tests\Controller;

use App\Controller\DeliveryController;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del orden del listado de reparto v2 (modo "grupo"). Blinda la deuda
 * "orden proyección ≠ piedra": el modo por defecto agrupaba por grupo de
 * recogida confiando en el ORDER BY del query, que SOLO existe cuando la semana
 * está congelada (piedra). Dibujada al vuelo (proyección) las entregas llegan
 * sin orden, así que el listado se reordenaba según estuviera generada o no.
 *
 * Aquí se invoca el método privado sectionsByGroup por reflexión (no necesita
 * BBDD: solo entidades en memoria) y se comprueba que dos órdenes de entrada
 * distintos —simulando piedra vs dibujo— producen EXACTAMENTE la misma salida,
 * ordenada de forma estable.
 */
class DeliveryByNodeOrderingTest extends TestCase
{
    public function testOrdenEstableIndependienteDelOrdenDeEntrada(): void
    {
        // Mismo conjunto de entregas en DOS órdenes distintos: el "piedra" imita
        // el ORDER BY del query (grupo, modalidad, nombre); el "dibujo" llega en
        // orden inverso, como podría llegar la proyección al vuelo. Inverso (no
        // shuffle) para que el test sea determinista y siempre arranque de un
        // orden distinto al esperado.
        $stone = $this->scenario();
        $draw = array_reverse($this->scenario());

        $stoneSections = $this->sectionsByGroup($stone);
        $drawSections = $this->sectionsByGroup($draw);

        $this->assertEquals(
            $this->flatten($stoneSections),
            $this->flatten($drawSections),
            'El listado debe salir idéntico venga de piedra o de dibujo.',
        );
    }

    public function testOrdenAlfabeticoDeGruposModalidadesYNombres(): void
    {
        $sections = $this->sectionsByGroup($this->scenario());

        // Grupos por nombre natural: Bustarviejo antes que Torremocha.
        $this->assertSame(['Bustarviejo', 'Torremocha'], array_column($sections, 'title'));

        // Dentro de Torremocha: subgrupos por basket_share.id (Semanal=1 antes
        // que Quincenal=2), y filas por nombre de reparto.
        $torre = $sections[1];
        $this->assertSame([1, 2], array_column($torre['subgroups'], 'bs_id'));

        $semanales = $torre['subgroups'][0]['rows'];
        $this->assertSame(
            ['Ana', 'Cris'],
            array_map(static fn (WeeklyBasket $wb): string => $wb->getPartner()->getNameForDelivery(), $semanales),
            'Las filas se ordenan por nombre de reparto.',
        );
    }

    /**
     * Comprueba que el orden es por nameForDelivery (lo que se imprime), no por
     * el campo crudo p.name: un socio con apodo de reparto se ordena por el
     * apodo, aunque su name legal cayera en otro sitio.
     */
    public function testOrdenaPorNombreDeRepartoNoPorNombreLegal(): void
    {
        $g = $this->group(1, 'Cabanillas');
        // name legal "Zacarías" pero apodo de reparto "Abel" → debe ir primero.
        $abel = $this->delivery($this->partnerWithLegalName(1, 'Zacarías', displayName: 'Abel'), $g, bsId: 1);
        $bea = $this->delivery($this->partnerWithLegalName(2, 'Bea', displayName: null), $g, bsId: 1);

        $rows = $this->sectionsByGroup([$bea, $abel])[0]['subgroups'][0]['rows'];

        $this->assertSame(
            ['Abel', 'Bea'],
            array_map(static fn (WeeklyBasket $wb): string => $wb->getPartner()->getNameForDelivery(), $rows),
        );
    }

    // --------------------------------------------------------------------- //

    /**
     * Escenario: dos grupos (Torremocha, Bustarviejo) con varias modalidades y
     * nombres deliberadamente desordenados respecto al orden final esperado.
     *
     * @return WeeklyBasket[]
     */
    private function scenario(): array
    {
        $torre = $this->group(20, 'Torremocha');
        $busta = $this->group(3, 'Bustarviejo');

        return [
            $this->delivery($this->partner(1, 'Cris'), $torre, bsId: 1),
            $this->delivery($this->partner(2, 'Ana'), $torre, bsId: 1),
            $this->delivery($this->partner(3, 'Miguel'), $torre, bsId: 2),
            $this->delivery($this->partner(4, 'Victoria'), $busta, bsId: 2),
            $this->delivery($this->partner(5, 'Berta'), $busta, bsId: 2),
        ];
    }

    /**
     * Proyección serializable de las secciones para comparar dos salidas sin
     * depender de la identidad de los objetos: solo el orden de (grupo,
     * modalidad, nombre de reparto).
     *
     * @param list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}> $sections
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $sections): array
    {
        return array_map(
            static fn (array $section): array => [
                'title' => $section['title'],
                'subgroups' => array_map(
                    static fn (array $sub): array => [
                        'bs_id' => $sub['bs_id'],
                        'rows' => array_map(
                            static fn (WeeklyBasket $wb): string => $wb->getPartner()->getNameForDelivery(),
                            $sub['rows'],
                        ),
                    ],
                    $section['subgroups'],
                ),
            ],
            $sections,
        );
    }

    /**
     * Invoca el método privado sectionsByGroup del controller por reflexión.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsByGroup(array $weeklyBaskets): array
    {
        $controller = new DeliveryController();
        $method = new \ReflectionMethod($controller, 'sectionsByGroup');
        $method->setAccessible(true);

        return $method->invoke($controller, $weeklyBaskets);
    }

    private function group(int $id, string $name): WeeklyBasketGroup
    {
        $wbg = (new WeeklyBasketGroup())->setName($name);
        $this->setId($wbg, $id);

        return $wbg;
    }

    private function partner(int $id, string $displayName): Partner
    {
        $partner = (new Partner())->setDisplayName($displayName);
        $this->setId($partner, $id);

        return $partner;
    }

    private function partnerWithLegalName(int $id, string $name, ?string $displayName): Partner
    {
        $partner = (new Partner())->setname($name)->setDisplayName($displayName);
        $this->setId($partner, $id);

        return $partner;
    }

    private function delivery(Partner $partner, WeeklyBasketGroup $group, int $bsId): WeeklyBasket
    {
        $bs = new BasketShare();
        $bs->setName('share-' . $bsId);
        $bs->setId($bsId);

        return (new WeeklyBasket())
            ->setPartner($partner)
            ->setWeeklyBasketGroup($group)
            ->setBasketShare($bs);
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
