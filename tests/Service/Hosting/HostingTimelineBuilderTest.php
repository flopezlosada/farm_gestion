<?php

namespace App\Tests\Service\Hosting;

use App\Entity\Helper;
use App\Entity\HostingCapacity;
use App\Entity\Stay;
use App\Repository\HostingCapacityRepository;
use App\Repository\StayRepository;
use App\Service\AppSettings;
use App\Service\Hosting\HostingCapacityResolver;
use App\Service\Hosting\HostingTimelineBuilder;
use App\Service\Hosting\OccupancyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del HostingTimelineBuilder. Mockea las fronteras (repos + settings)
 * y usa la lógica propia real (resolver + checker). Verifica el recorte de
 * estancias a la ventana (offset/span y banderas de recorte) y la ocupación
 * diaria con su estado (libre/lleno/sobre aforo), incluyendo que las solicitadas
 * pintan barra pero NO cuentan para el aforo.
 */
class HostingTimelineBuilderTest extends TestCase
{
    public function testConstruyeFilasYOcupacionDeLaVentana(): void
    {
        $helperA = $this->helper(1, 'Ana');
        $helperB = $this->helper(2, 'Beto');

        // Ventana: 01..07 jul 2026 (07 días). Aforo julio = 2.
        $stays = [
            $this->stay($helperA, '2026-07-02', '2026-07-05', Stay::STATUS_CONFIRMED),  // ocupa 2,3,4
            $this->stay($helperA, '2026-07-06', '2026-07-09', Stay::STATUS_REQUESTED),  // recortada por la derecha
            $this->stay($helperB, '2026-06-30', '2026-07-03', Stay::STATUS_CONFIRMED),  // recortada por la izquierda; ocupa 1,2
        ];

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findOverlapping')->willReturn($stays);

        $builder = new HostingTimelineBuilder(
            $stayRepository,
            $this->resolverWithJulyCapacity(2),
            new OccupancyChecker(),
        );

        $timeline = $builder->buildDaily(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-08'));

        // Ventana.
        $this->assertSame('day', $timeline['unit']);
        $this->assertSame(7, $timeline['col_count']);
        $this->assertCount(7, $timeline['cols']);

        // Ocupación (sólo confirmadas): 1/jul=1(B), 2/jul=2(A+B)=lleno, 3/jul=1(A),
        // 5/jul=0 (A se fue el 5; la solicitada del 6 no cuenta).
        $this->assertSame(1, $timeline['cols'][0]['occupied']);
        $this->assertSame('free', $timeline['cols'][0]['state']);
        $this->assertSame(2, $timeline['cols'][1]['occupied']);
        $this->assertSame('full', $timeline['cols'][1]['state']);
        $this->assertSame(1, $timeline['cols'][2]['occupied']);
        $this->assertSame(0, $timeline['cols'][4]['occupied']);  // 5/jul

        // Filas: Ana (2 segmentos) y Beto (1 segmento).
        $this->assertCount(2, $timeline['rows']);

        $ana = $timeline['rows'][0];
        $this->assertSame('Ana', $ana['helper']->getName());
        $this->assertCount(2, $ana['segments']);
        $this->assertSame(['offset' => 1, 'span' => 3, 'status' => Stay::STATUS_CONFIRMED, 'clipped_left' => false, 'clipped_right' => false], $ana['segments'][0]);
        // La solicitada 06→09 se recorta a 06→08 (span 2) y marca recorte derecho.
        $this->assertSame(['offset' => 5, 'span' => 2, 'status' => Stay::STATUS_REQUESTED, 'clipped_left' => false, 'clipped_right' => true], $ana['segments'][1]);

        $beto = $timeline['rows'][1];
        $this->assertSame('Beto', $beto['helper']->getName());
        // La de Beto 30/jun→03/jul se recorta a 01→03 (offset 0, span 2) y marca recorte izquierdo.
        $this->assertSame(['offset' => 0, 'span' => 2, 'status' => Stay::STATUS_CONFIRMED, 'clipped_left' => true, 'clipped_right' => false], $beto['segments'][0]);
    }

    public function testMesCerradoConGenteSaleSobreAforo(): void
    {
        $helper = $this->helper(1, 'Ana');
        $stays = [$this->stay($helper, '2026-08-02', '2026-08-04', Stay::STATUS_CONFIRMED)];  // 2,3 ago

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findOverlapping')->willReturn($stays);

        // Agosto cerrado (aforo 0) pero hay una confirmada → días con gente = sobre aforo.
        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->method('findKeyedByMonth')
            ->willReturn(['2026-08' => (new HostingCapacity())->setYear(2026)->setMonth(8)->setOpen(false)->setCapacity(0)]);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->willReturn(0);

        $builder = new HostingTimelineBuilder(
            $stayRepository,
            new HostingCapacityResolver($capacityRepository, $settings),
            new OccupancyChecker(),
        );

        $timeline = $builder->buildDaily(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'));

        $this->assertSame('closed', $timeline['cols'][0]['state']);  // 1 ago: cerrado, sin nadie
        $this->assertSame('over', $timeline['cols'][1]['state']);    // 2 ago: cerrado pero ocupado
        $this->assertSame('over', $timeline['cols'][2]['state']);    // 3 ago
        $this->assertSame('closed', $timeline['cols'][3]['state']);  // 4 ago: salió
    }

    public function testVistaMensualUsaElPicoDelMes(): void
    {
        $ana = $this->helper(1, 'Ana');
        $beto = $this->helper(2, 'Beto');

        // Ana cruza julio→agosto; Beto solapa con ella unos días de julio.
        // Julio: pico 2 (Ana+Beto). Agosto: pico 1 (Ana). Septiembre: nadie.
        $stays = [
            $this->stay($ana, '2026-07-05', '2026-08-10', Stay::STATUS_CONFIRMED),
            $this->stay($beto, '2026-07-10', '2026-07-15', Stay::STATUS_CONFIRMED),  // solapa con Ana
        ];

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findOverlapping')->willReturn($stays);

        $builder = new HostingTimelineBuilder(
            $stayRepository,
            $this->resolverWithJulyCapacity(2),  // aforo 2 (vale para todos los meses sin fila: físico 0... ver nota)
            new OccupancyChecker(),
        );

        $timeline = $builder->buildMonthly(new \DateTimeImmutable('2026-07-15'), 3);

        $this->assertSame('month', $timeline['unit']);
        $this->assertSame(3, $timeline['col_count']);
        $this->assertSame('2026-07', $timeline['cols'][0]['date']->format('Y-m'));
        // Julio: pico 2 (Ana+Beto solapadas) → lleno con aforo 2.
        $this->assertSame(2, $timeline['cols'][0]['occupied']);
        $this->assertSame('full', $timeline['cols'][0]['state']);
        // Agosto: pico 1 → libre.
        $this->assertSame(1, $timeline['cols'][1]['occupied']);
        // Septiembre: nadie.
        $this->assertSame(0, $timeline['cols'][2]['occupied']);

        // Ana abarca julio y agosto (2 meses): un segmento offset 0, span 2.
        $ana_row = $timeline['rows'][0];
        $this->assertSame('Ana', $ana_row['helper']->getName());
        $this->assertSame(0, $ana_row['segments'][0]['offset']);
        $this->assertSame(2, $ana_row['segments'][0]['span']);
    }

    /**
     * Resolver real con aforo de julio dado y físico de referencia 0.
     */
    private function resolverWithJulyCapacity(int $capacity): HostingCapacityResolver
    {
        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->method('findKeyedByMonth')
            ->willReturn(['2026-07' => (new HostingCapacity())->setYear(2026)->setMonth(7)->setOpen(true)->setCapacity($capacity)]);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->willReturn(0);

        return new HostingCapacityResolver($capacityRepository, $settings);
    }

    /**
     * Helper mockeado con id y nombre (las entidades nuevas no tienen setId).
     */
    private function helper(int $id, string $name): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getId')->willReturn($id);
        $helper->method('getName')->willReturn($name);

        return $helper;
    }

    /**
     * Estancia de un voluntario con fechas y estado.
     */
    private function stay(Helper $helper, string $arrival, string $departure, string $status): Stay
    {
        return (new Stay())
            ->setHelper($helper)
            ->setArrivalDate(new \DateTimeImmutable($arrival))
            ->setDepartureDate(new \DateTimeImmutable($departure))
            ->setStatus($status);
    }
}
