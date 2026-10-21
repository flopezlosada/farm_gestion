<?php

namespace App\Tests\Service\Staff;

use App\Entity\Worker;
use App\Entity\WorkSchedule;
use App\Service\Staff\WorkingWeek;
use PHPUnit\Framework\TestCase;

/**
 * Qué días le toca trabajar a alguien según su horario: la regla que decide si
 * un día sin fichar es un hueco o su descanso.
 */
class WorkingWeekTest extends TestCase
{
    /** Lunes 15 de junio de 2026; +n días para el resto de la semana. */
    private function day(int $isoDayOfWeek): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-06-15'))->modify(sprintf('+%d days', $isoDayOfWeek - 1));
    }

    /**
     * @param list<array{0: string, 1: string}>|null $times
     */
    private function row(?Worker $worker, int $dayOfWeek, ?array $times): WorkSchedule
    {
        return (new WorkSchedule())->setWorker($worker)->setDayOfWeek($dayOfWeek)->setTimes($times);
    }

    /**
     * @return list<int> Días ISO de la semana que incluye.
     */
    private function days(WorkingWeek $week): array
    {
        return array_values(array_filter(range(1, 7), fn (int $d) => $week->includes($this->day($d))));
    }

    public function testSinHorarioConfiguradoEsDeLunesAViernes(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], $this->days(WorkingWeek::fromSchedules([])));
    }

    /**
     * La plantilla real de la asociación: lunes a jueves partida, viernes de
     * mañana, fin de semana sin filas.
     */
    public function testLaPlantillaGlobalDiceLosDiasConTramos(): void
    {
        $morning = [['09:00', '14:00']];
        $split = [['09:00', '14:00'], ['15:30', '18:00']];
        $week = WorkingWeek::fromSchedules([
            $this->row(null, 1, $split),
            $this->row(null, 2, $split),
            $this->row(null, 3, $split),
            $this->row(null, 4, $split),
            $this->row(null, 5, $morning),
        ]);

        $this->assertSame([1, 2, 3, 4, 5], $this->days($week));
    }

    /**
     * La excepción del trabajador manda sobre la global, venga antes o después
     * en la lista: con el viernes vacío, el viernes no trabaja; con el sábado
     * con tramos, el sábado sí.
     */
    public function testLaExcepcionDelTrabajadorPisaLaGlobal(): void
    {
        $worker = new Worker();
        $times = [['09:00', '14:00']];
        $globals = array_map(fn (int $d) => $this->row(null, $d, $times), [1, 2, 3, 4, 5]);
        $own = [$this->row($worker, 5, null), $this->row($worker, 6, $times)];

        $this->assertSame([1, 2, 3, 4, 6], $this->days(WorkingWeek::fromSchedules([...$globals, ...$own])));
        $this->assertSame([1, 2, 3, 4, 6], $this->days(WorkingWeek::fromSchedules([...$own, ...$globals])));
    }

    /**
     * Sin plantilla global, una única excepción (el viernes libre) no deja el
     * resto de la semana sin huecos: los días sin fila caen a lunes-viernes.
     */
    public function testUnDiaSinNingunaFilaCaeALunesAViernes(): void
    {
        $week = WorkingWeek::fromSchedules([$this->row(new Worker(), 5, null)]);

        $this->assertSame([1, 2, 3, 4], $this->days($week));
    }

    /**
     * Dos filas globales para el mismo día (la BD no lo impide): gana la
     * primera, que el repositorio entrega por id.
     */
    public function testConFilasDuplicadasGanaLaPrimera(): void
    {
        $week = WorkingWeek::fromSchedules([
            $this->row(null, 6, [['09:00', '14:00']]),
            $this->row(null, 6, null),
        ]);

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->days($week));
    }

    public function testUnDiaInexistenteNoSeAdmite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkingWeek::of(8);
    }
}
