<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\City;
use App\Entity\EggAmount;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\DeliveryLine;
use App\Service\Delivery\HelperDeliveryResolver;
use App\Service\Delivery\NodeDeliverySheet;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del NodeDeliverySheet con repositorios mockeados. Verifica el
 * volcado al formato papel: grupos alfabéticos con subgrupos por modalidad y
 * subtotal, bloque de compartidas aparte con las parejas pegadas, notación de
 * huevos compacta y totales del nodo.
 */
class NodeDeliverySheetTest extends TestCase
{
    private NodeDeliverySheet $sheet;
    /** @var array<int,WeeklyBasket> */
    private array $wbs = [];
    /** @var array<int,array<int,float>> wb.id => [componentId => amount] */
    private array $amounts = [];
    /** @var array<int,?PartnerBasketShare> partner.id => PBS activo */
    private array $pbsByPartner = [];
    /** @var DeliveryLine[] líneas de voluntarios del albergue que devuelve el resolver mockeado */
    private array $helperLines = [];

    public function testHojaCompletaDeUnNodo(): void
    {
        $this->populateFullNodeScenario();

        $result = $this->buildSheet();

        // --- Grupos: orden alfabético, compartidas excluidas ---
        $groups = $result['groups'];
        $this->assertSame(['Bustarviejo', 'Torremocha'], array_column($groups, 'name'));

        $torreGroup = $groups[1];
        $this->assertSame(2.0, $torreGroup['subtotal_cestas'], 'subtotal = 1 + 1 + 0 (solo huevos)');
        $this->assertSame('2D+1M', $torreGroup['subtotal_eggs'], 'huevos del grupo = 1D (Cris) + 1D+1M (Raquel) = 2,5 docenas');
        $this->assertSame('Torremocha', $torreGroup['locality']);
        $this->assertSame(
            ['Semanales', 'Quincenales y mensuales', 'Solo huevos'],
            array_column($torreGroup['modalities'], 'label'),
            'buckets de modalidad del Excel, en orden',
        );

        // Fila semanal con huevos: código SH, "1D" / 12.
        $crisRow = $torreGroup['modalities'][0]['rows'][0];
        $this->assertSame('SH', $crisRow['code']);
        $this->assertSame('1D', $crisRow['egg_spec']);
        $this->assertSame(12, $crisRow['egg_count']);

        // Solo huevos: código H, "1D+1M" / 18.
        $raquelRow = $torreGroup['modalities'][2]['rows'][0];
        $this->assertSame('H', $raquelRow['code']);
        $this->assertSame('1D+1M', $raquelRow['egg_spec']);
        $this->assertSame(18, $raquelRow['egg_count']);

        // Quincenal sin huevos: código Q, sin spec.
        $miguelRow = $torreGroup['modalities'][1]['rows'][0];
        $this->assertSame('Q', $miguelRow['code']);
        $this->assertNull($miguelRow['egg_spec']);

        // Bustarviejo: quincenal con huevos → QH, "1M" / 6.
        $bustaGroup = $groups[0];
        $this->assertSame(1.0, $bustaGroup['subtotal_cestas']);
        $this->assertSame('1M', $bustaGroup['subtotal_eggs'], 'huevos del grupo = 1M (Victoria, media docena)');
        $victoriaRow = $bustaGroup['modalities'][0]['rows'][0];
        $this->assertSame('QH', $victoriaRow['code']);
        $this->assertSame('1M', $victoriaRow['egg_spec']);
        $this->assertSame(6, $victoriaRow['egg_count']);

        // --- Compartidas: pareja pegada (Eduardo, Pilar) + huérfana (Hilde) ---
        $sharedRows = $result['shared']['rows'];
        $this->assertSame(['Eduardo y Pilar', 'Pilar y Eduardo', 'Hilde'], array_column($sharedRows, 'name'));
        $this->assertSame(['SC', 'SC', 'QC'], array_column($sharedRows, 'code'));
        $this->assertFalse($sharedRows[0]['pair_end'], 'el primero de la pareja no cierra grupo');
        $this->assertTrue($sharedRows[1]['pair_end'], 'el segundo de la pareja cierra grupo');
        $this->assertTrue($sharedRows[2]['pair_end'], 'la huérfana cierra su propio grupo');
        $this->assertSame('Torremocha', $sharedRows[0]['locality'], 'la compartida lleva su localidad');
        $this->assertSame('#85c0e0', $sharedRows[0]['color'], 'la compartida lleva el color de su grupo');

        // --- Totales del nodo ---
        $this->assertSame(4.5, $result['totals']['cestas'], '1+1+0 +1 +0,5+0,5+0,5');
        $this->assertSame(3.0, $result['totals']['docenas'], '1 +1,5 +0,5');
    }

    /**
     * GOLDEN: congela la salida ENTERA de build() para el escenario rico. A
     * diferencia de {@see testHojaCompletaDeUnNodo} (que comprueba campos
     * sueltos), aquí se aserta el array completo, así que cualquier cambio en el
     * shaping —orden, claves, valores, campos nuevos— rompe el test. Es la red
     * que blinda el refactor de NodeDeliverySheet a DTO de líneas: reordenar la
     * fuente de datos NO debe alterar ni un byte del listado.
     *
     * Si este test se vuelve rojo a propósito (cambio real del formato), hay que
     * regenerar el esperado de abajo CONSCIENTEMENTE, no a la ligera.
     */
    public function testGoldenSnapshotHojaCompleta(): void
    {
        $this->populateFullNodeScenario();

        $result = $this->buildSheet();

        $this->assertEquals(
            [
                'groups' => [
                    [
                        'name' => 'Bustarviejo',
                        'color' => '#e0a585',
                        'locality' => 'Bustarviejo',
                        'subtotal_cestas' => 1.0,
                        'subtotal_eggs' => '1M',
                        'modalities' => [
                            [
                                'label' => 'Quincenales y mensuales',
                                'rows' => [
                                    ['name' => 'Victoria y David', 'code' => 'QH', 'cestas' => 1.0, 'egg_spec' => '1M', 'egg_count' => 6, 'locality' => 'Bustarviejo', 'relocated_from' => null],
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Torremocha',
                        'color' => '#85c0e0',
                        'locality' => 'Torremocha',
                        'subtotal_cestas' => 2.0,
                        'subtotal_eggs' => '2D+1M',
                        'modalities' => [
                            [
                                'label' => 'Semanales',
                                'rows' => [
                                    ['name' => 'Cris y Paco', 'code' => 'SH', 'cestas' => 1.0, 'egg_spec' => '1D', 'egg_count' => 12, 'locality' => 'Torremocha', 'relocated_from' => null],
                                ],
                            ],
                            [
                                'label' => 'Quincenales y mensuales',
                                'rows' => [
                                    ['name' => 'Miguel y Paloma', 'code' => 'Q', 'cestas' => 1.0, 'egg_spec' => null, 'egg_count' => 0, 'locality' => 'Torremocha', 'relocated_from' => null],
                                ],
                            ],
                            [
                                'label' => 'Solo huevos',
                                'rows' => [
                                    ['name' => 'Raquel', 'code' => 'H', 'cestas' => 0.0, 'egg_spec' => '1D+1M', 'egg_count' => 18, 'locality' => 'Torremocha', 'relocated_from' => null],
                                ],
                            ],
                        ],
                    ],
                ],
                'shared' => [
                    'rows' => [
                        ['name' => 'Eduardo y Pilar', 'code' => 'SC', 'cestas' => 0.5, 'egg_spec' => null, 'egg_count' => 0, 'locality' => 'Torremocha', 'relocated_from' => null, 'color' => '#85c0e0', 'pair_end' => false],
                        ['name' => 'Pilar y Eduardo', 'code' => 'SC', 'cestas' => 0.5, 'egg_spec' => null, 'egg_count' => 0, 'locality' => 'Torremocha', 'relocated_from' => null, 'color' => '#85c0e0', 'pair_end' => true],
                        ['name' => 'Hilde', 'code' => 'QC', 'cestas' => 0.5, 'egg_spec' => null, 'egg_count' => 0, 'locality' => 'Bustarviejo', 'relocated_from' => null, 'color' => '#e0a585', 'pair_end' => true],
                    ],
                    'subtotal_cestas' => 1.5,
                    'subtotal_eggs' => null,
                ],
                'totals' => ['cestas' => 4.5, 'docenas' => 3.0],
            ],
            $result,
        );
    }

    public function testOrdenPorCodigoDentroDeModalidad(): void
    {
        $g = $this->group(3, 'Bustarviejo', '#e0a585');
        $this->delivery(1, 'Zoe', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 1.0, eggs: true);   // QH
        $this->delivery(2, 'Ana', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);  // Q
        $this->delivery(3, 'Bea', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);  // Q

        $rows = $this->buildSheet()['groups'][0]['modalities'][0]['rows'];

        // Q (Ana, Bea) antes que QH (Zoe); dentro del mismo código, alfabético.
        $this->assertSame(['Ana', 'Bea', 'Zoe'], array_column($rows, 'name'));
        $this->assertSame(['Q', 'Q', 'QH'], array_column($rows, 'code'));
    }

    public function testLocalidadQuitaSufijoIndividuales(): void
    {
        $g = $this->group(50, 'La Cabrera individuales', '#9ae085');
        $this->delivery(1, 'Eva', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);

        $group = $this->buildSheet()['groups'][0];
        $this->assertSame('La Cabrera individuales', $group['name'], 'el nombre del grupo se conserva');
        $this->assertSame('La Cabrera', $group['locality'], 'la celda de localidad quita " individuales"');
    }

    public function testGrupoCombinadoUsaCiudadDelSocio(): void
    {
        // Grupo combinado (nombre con "/"): la celda es el pueblo de cada socio.
        $g = $this->group(2, 'Alcobendas/Sanse/Tres Cantos', '#e09585');
        $jorge = $this->delivery(1, 'Jorge Ortega', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);
        $jorge->getPartner()->setCity($this->city('Tres Cantos'));
        $sinCiudad = $this->delivery(2, 'Sin Ciudad', $g, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);

        $rows = $this->buildSheet()['groups'][0]['modalities'][0]['rows'];
        $byName = array_column($rows, 'locality', 'name');

        $this->assertSame('Tres Cantos', $byName['Jorge Ortega'], 'con city → el pueblo del socio');
        $this->assertSame('Alcobendas/Sanse/Tres Cantos', $byName['Sin Ciudad'], 'sin city → cae al nombre del grupo');
    }

    public function testNodoSinRepartoDevuelveHojaVacia(): void
    {
        $result = $this->buildSheet();

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['shared']['rows']);
        $this->assertSame(['cestas' => 0.0, 'docenas' => 0.0], $result['totals']);
    }

    // --------------------------------------------------------------------- //

    /**
     * Ejecuta el servicio con los repos mockeados a partir del escenario
     * montado con {@see delivery()} / {@see group()}.
     *
     * @return array<string,mixed>
     */
    /**
     * Las cestas de voluntarios del albergue (líneas isHelper) van a un grupo
     * propio "Albergue" al final, no a "Cestas extra" aunque no tengan modalidad,
     * y sus cantidades cuentan en los totales del nodo.
     */
    public function testHelperBasketsFormOwnGroupAndCountInTotals(): void
    {
        // Sin socios materializados; sólo dos voluntarios derivados.
        $this->helperLines = [
            new DeliveryLine(
                nameForDelivery: 'Germán',
                basketShareId: null,
                subscribedToEggs: true,
                cestas: 1.0,
                dozens: 1.0,
                groupId: null,
                groupName: null,
                groupColor: null,
                city: null,
                partnerId: null,
                sharePartnerId: null,
                relocatedFromLabel: null,
                isHelper: true,
            ),
            new DeliveryLine(
                nameForDelivery: 'Marie',
                basketShareId: null,
                subscribedToEggs: false,
                cestas: 2.0,
                dozens: 0.0,
                groupId: null,
                groupName: null,
                groupColor: null,
                city: null,
                partnerId: null,
                sharePartnerId: null,
                relocatedFromLabel: null,
                isHelper: true,
            ),
        ];

        $result = $this->buildSheet();

        $this->assertSame(['Albergue'], array_column($result['groups'], 'name'), 'Sin socios, el único grupo es Albergue.');
        $albergue = $result['groups'][0];
        $rows = $albergue['modalities'][0]['rows'];
        $this->assertSame(['Germán', 'Marie'], array_column($rows, 'name'), 'Ordenados por nombre.');
        $this->assertSame('', $rows[0]['code'], 'Sin código de modalidad.');
        $this->assertSame('1D', $rows[0]['egg_spec']);
        $this->assertNull($rows[1]['egg_spec'], 'Marie no lleva huevos.');
        $this->assertSame('1D', $albergue['subtotal_eggs'], 'subtotal huevos Albergue = 1D (Germán) + 0 (Marie)');

        // Totales: 1 + 2 = 3 cestas, 1 docena.
        $this->assertSame(3.0, $result['totals']['cestas']);
        $this->assertSame(1.0, $result['totals']['docenas']);
    }

    private function buildSheet(): array
    {
        $wbs = array_values($this->wbs);
        $amounts = $this->amounts;
        $pbsByPartner = $this->pbsByPartner;

        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findForNodeAndBasket')->willReturn($wbs);

        $itemRepo = $this->createMock(WeeklyBasketItemRepository::class);
        $itemRepo->method('componentAmountsFor')->willReturn($amounts);

        $pbsRepo = $this->createMock(PartnerBasketShareRepository::class);
        $pbsRepo->method('findActiveForPartner')->willReturnCallback(
            static fn (Partner $p): ?PartnerBasketShare => $pbsByPartner[$p->getId()] ?? null,
        );

        $helperResolver = $this->createMock(HelperDeliveryResolver::class);
        $helperResolver->method('forNodeAndBasket')->willReturn($this->helperLines);

        $this->sheet = new NodeDeliverySheet($wbRepo, $itemRepo, $pbsRepo, $helperResolver);

        return $this->sheet->build(new Node(), (new Basket())->setDate(new \DateTime('2026-05-15')));
    }

    /**
     * Escenario rico compartido por {@see testHojaCompletaDeUnNodo} y el golden:
     * dos grupos regulares (Torremocha, Bustarviejo) con las tres modalidades y
     * el bloque de compartidas con una pareja pegada (Pilar↔Eduardo) y una
     * huérfana (Hilde).
     */
    private function populateFullNodeScenario(): void
    {
        // ---- Grupo Torremocha (orden de modalidad y subtotal) ----
        $torre = $this->group(20, 'Torremocha', '#85c0e0');
        $this->delivery(1, 'Cris y Paco', $torre, bsId: 1, bsName: 'Semanal', cestas: 1.0, dozens: 1.0, eggs: true);
        $this->delivery(2, 'Miguel y Paloma', $torre, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.0, eggs: false);
        $this->delivery(3, 'Raquel', $torre, bsId: 5, bsName: 'Solo huevos', cestas: 0.0, dozens: 1.5, eggs: false);

        // ---- Grupo Bustarviejo (alfabéticamente antes que Torremocha) ----
        $busta = $this->group(3, 'Bustarviejo', '#e0a585');
        $this->delivery(4, 'Victoria y David', $busta, bsId: 2, bsName: 'Quincenal', cestas: 1.0, dozens: 0.5, eggs: true);

        // ---- Compartidas (bloque aparte, parejas pegadas) ----
        $pilar = $this->delivery(5, 'Pilar y Eduardo', $torre, bsId: 4, bsName: 'Semanal compartida', cestas: 0.5, dozens: 0.0, eggs: false);
        $eduardo = $this->delivery(6, 'Eduardo y Pilar', $torre, bsId: 4, bsName: 'Semanal compartida', cestas: 0.5, dozens: 0.0, eggs: false);
        $this->pairUp($pilar, $eduardo);
        $this->delivery(7, 'Hilde', $busta, bsId: 6, bsName: 'Quincenal compartida', cestas: 0.5, dozens: 0.0, eggs: false);
    }

    private function group(int $id, string $name, string $color): WeeklyBasketGroup
    {
        $wbg = (new WeeklyBasketGroup())->setName($name)->setColor($color);
        $this->setId($wbg, $id);

        return $wbg;
    }

    /**
     * Crea una entrega (WeeklyBasket) en el escenario y registra sus cantidades
     * materializadas y el PBS activo del socio (suscrito o no a huevos).
     */
    private function delivery(
        int $id,
        string $name,
        WeeklyBasketGroup $group,
        int $bsId,
        string $bsName,
        float $cestas,
        float $dozens,
        bool $eggs,
    ): WeeklyBasket {
        $partner = (new Partner())->setDisplayName($name);
        $this->setId($partner, $id);

        $bs = new BasketShare();
        $bs->setName($bsName);
        $bs->setId($bsId);

        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setWeeklyBasketGroup($group)
            ->setBasketShare($bs)
            ->setAmount((int) $cestas);
        $this->setId($wb, $id);

        $this->wbs[$id] = $wb;
        $this->amounts[$id] = [
            BasketComponent::ID_VEGETABLES => $cestas,
            BasketComponent::ID_EGGS => $dozens,
        ];

        $pbs = new PartnerBasketShare();
        if ($eggs) {
            $eggAmount = $this->createMock(EggAmount::class);
            $pbs->setEggAmount($eggAmount);
        }
        $this->pbsByPartner[$id] = $pbs;

        return $wb;
    }

    private function city(string $name): City
    {
        $city = $this->createMock(City::class);
        $city->method('getName')->willReturn($name);

        return $city;
    }

    private function pairUp(WeeklyBasket $a, WeeklyBasket $b): void
    {
        $a->getPartner()->setSharePartner($b->getPartner());
        $b->getPartner()->setSharePartner($a->getPartner());
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
