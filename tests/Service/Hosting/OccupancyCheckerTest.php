<?php

namespace App\Tests\Service\Hosting;

use App\Entity\Stay;
use App\Service\Hosting\OccupancyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del OccupancyChecker. Lógica pura de intervalos: sin BBDD ni mocks,
 * sólo estancias en memoria y una función de aforo por día. Cubre los bordes que
 * parecen triviales y luego fallan: día de salida (libera cama), estancias
 * adyacentes (no solapan), meses cerrados (aforo 0) y estancias a caballo entre
 * dos meses con aforo distinto.
 */
class OccupancyCheckerTest extends TestCase
{
    private OccupancyChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new OccupancyChecker();
    }

    public function testOcupaDentroDelRango(): void
    {
        $stay = $this->confirmed('2026-07-10', '2026-07-15');

        $this->assertTrue($this->checker->occupies($stay, $this->day('2026-07-10')));  // llegada incluida
        $this->assertTrue($this->checker->occupies($stay, $this->day('2026-07-12')));  // en medio
    }

    public function testDiaDeSalidaLiberaLaCama(): void
    {
        $stay = $this->confirmed('2026-07-10', '2026-07-15');

        // La salida es EXCLUIDA: el día 15 la cama ya está libre.
        $this->assertFalse($this->checker->occupies($stay, $this->day('2026-07-15')));
    }

    public function testNoOcupaAntesDeLlegar(): void
    {
        $stay = $this->confirmed('2026-07-10', '2026-07-15');

        $this->assertFalse($this->checker->occupies($stay, $this->day('2026-07-09')));
    }

    public function testSoloLasConfirmadasOcupan(): void
    {
        $day = $this->day('2026-07-12');

        $requested = $this->stay('2026-07-10', '2026-07-15', Stay::STATUS_REQUESTED);
        $cancelled = $this->stay('2026-07-10', '2026-07-15', Stay::STATUS_CANCELLED);

        $this->assertFalse($this->checker->occupies($requested, $day));
        $this->assertFalse($this->checker->occupies($cancelled, $day));
    }

    public function testEstanciasAdyacentesNoSolapan(): void
    {
        $a = $this->confirmed('2026-07-10', '2026-07-15');
        $b = $this->confirmed('2026-07-15', '2026-07-20');  // entra justo el día que sale A

        $this->assertFalse($this->checker->overlap($a, $b));
        $this->assertFalse($this->checker->overlap($b, $a));
    }

    public function testEstanciasQueSeSolapan(): void
    {
        $a = $this->confirmed('2026-07-10', '2026-07-16');
        $b = $this->confirmed('2026-07-15', '2026-07-20');

        $this->assertTrue($this->checker->overlap($a, $b));
        $this->assertTrue($this->checker->overlap($b, $a));
    }

    public function testOcupacionCuentaSoloLasConfirmadasPresentes(): void
    {
        $day = $this->day('2026-07-12');
        $stays = [
            $this->confirmed('2026-07-10', '2026-07-15'),                 // ocupa
            $this->confirmed('2026-07-12', '2026-07-13'),                 // ocupa
            $this->confirmed('2026-07-20', '2026-07-25'),                 // fuera
            $this->stay('2026-07-10', '2026-07-15', Stay::STATUS_REQUESTED), // no confirmada
        ];

        $this->assertSame(2, $this->checker->occupancyOn($day, $stays));
    }

    public function testCabeCuandoHayAforoDeSobra(): void
    {
        $candidate = $this->confirmed('2026-07-10', '2026-07-13');
        $existing = [$this->confirmed('2026-07-10', '2026-07-15')];  // 1 ocupada

        // Aforo 5 todos los días → con el candidato serían 2, cabe.
        $this->assertSame([], $this->checker->violationsFor($candidate, $existing, fn () => 5));
        $this->assertTrue($this->checker->fits($candidate, $existing, fn () => 5));
    }

    public function testNoCabeYDevuelveLosDiasEnConflicto(): void
    {
        $candidate = $this->confirmed('2026-07-10', '2026-07-13');  // 10, 11, 12
        $existing = [
            $this->confirmed('2026-07-10', '2026-07-12'),  // ocupa 10 y 11
            $this->confirmed('2026-07-10', '2026-07-12'),  // ocupa 10 y 11
        ];

        // Aforo 2: el 10 y el 11 ya están a 2; meter al candidato (3) viola. El
        // 12 sólo lo ocuparía el candidato (1 <= 2), no viola.
        $violations = $this->checker->violationsFor($candidate, $existing, fn () => 2);

        $this->assertSame(['2026-07-10', '2026-07-11'], $this->isoDays($violations));
        $this->assertFalse($this->checker->fits($candidate, $existing, fn () => 2));
    }

    public function testMesCerradoAforoCeroTodoElRangoEnConflicto(): void
    {
        $candidate = $this->confirmed('2026-08-01', '2026-08-04');  // 1, 2, 3

        // Agosto cerrado → aforo efectivo 0: ningún día admite a nadie.
        $violations = $this->checker->violationsFor($candidate, [], fn () => 0);

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $this->isoDays($violations));
    }

    public function testAforoVariablePorMesEnEstanciaACaballo(): void
    {
        // Estancia que cruza de julio a agosto: 30/07, 31/07, 01/08, 02/08.
        $candidate = $this->confirmed('2026-07-30', '2026-08-03');
        // Ya hay 1 ocupada cada uno de esos días.
        $existing = [$this->confirmed('2026-07-25', '2026-08-10')];

        // Julio aforo 2 (cabe: 1+1=2), agosto aforo 1 (no cabe: 1+1=2 > 1).
        $capacityForDay = fn (\DateTimeImmutable $d) => $d->format('m') === '07' ? 2 : 1;

        $violations = $this->checker->violationsFor($candidate, $existing, $capacityForDay);

        $this->assertSame(['2026-08-01', '2026-08-02'], $this->isoDays($violations));
    }

    /**
     * Una estancia de un solo día (llegada y salida al día siguiente) ocupa
     * exactamente ese día.
     */
    public function testEstanciaDeUnSoloDia(): void
    {
        $candidate = $this->confirmed('2026-07-10', '2026-07-11');

        $this->assertTrue($this->checker->occupies($candidate, $this->day('2026-07-10')));
        $this->assertFalse($this->checker->occupies($candidate, $this->day('2026-07-11')));
        $this->assertSame([], $this->checker->violationsFor($candidate, [], fn () => 1));
    }

    /**
     * Construye una estancia confirmada entre dos fechas ISO.
     *
     * @param string $arrival   Fecha ISO de llegada.
     * @param string $departure Fecha ISO de salida.
     * @return Stay
     */
    private function confirmed(string $arrival, string $departure): Stay
    {
        return $this->stay($arrival, $departure, Stay::STATUS_CONFIRMED);
    }

    /**
     * Construye una estancia con el estado dado.
     *
     * @param string $arrival
     * @param string $departure
     * @param string $status
     * @return Stay
     */
    private function stay(string $arrival, string $departure, string $status): Stay
    {
        return (new Stay())
            ->setArrivalDate(new \DateTimeImmutable($arrival))
            ->setDepartureDate(new \DateTimeImmutable($departure))
            ->setStatus($status);
    }

    /**
     * Atajo para un día como DateTimeImmutable.
     *
     * @param string $iso
     * @return \DateTimeImmutable
     */
    private function day(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    /**
     * Mapea una lista de días a sus fechas ISO (YYYY-MM-DD) para aserciones.
     *
     * @param \DateTimeImmutable[] $days
     * @return string[]
     */
    private function isoDays(array $days): array
    {
        return array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $days);
    }
}
