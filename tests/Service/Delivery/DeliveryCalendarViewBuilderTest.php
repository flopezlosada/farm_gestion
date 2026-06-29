<?php

namespace App\Tests\Service\Delivery;

use App\Service\Delivery\DeliveryCalendarViewBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test unitario de la lógica de rejilla del calendario de recogida: el horizonte
 * que extiende la vista (y los destinos de arrastre) hasta la primera semana
 * completa del mes siguiente, para poder posponer el reparto del borde del mes.
 *
 * Solo ejercita métodos puros de fechas (gridHorizonEnd / buildMonthWeeks): no
 * tocan las dependencias, así que se construyen como dobles inertes.
 */
class DeliveryCalendarViewBuilderTest extends TestCase
{
    /**
     * Los métodos bajo prueba son lógica pura de fechas y no tocan las dependencias
     * inyectadas (proyector / generador / EM, todas `final` y no mockeables), así que
     * se instancia sin constructor: basta con tener un objeto sobre el que invocarlos.
     */
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $builder = (new \ReflectionClass(DeliveryCalendarViewBuilder::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionMethod(DeliveryCalendarViewBuilder::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($builder, ...$args);
    }

    /**
     * El horizonte es siempre el DOMINGO que cierra la primera semana completa
     * (lunes-domingo) del mes siguiente. Incluye el borde en que el día 1 del mes
     * siguiente ya es lunes (mayo→junio 2026): esa misma semana es la primera completa.
     *
     * @dataProvider horizonCases
     */
    public function testGridHorizonEndIsSundayOfFirstFullWeekOfNextMonth(string $month, string $expected): void
    {
        /** @var \DateTimeImmutable $result */
        $result = $this->invokePrivate('gridHorizonEnd', new \DateTimeImmutable($month . '-01'));

        $this->assertSame($expected, $result->format('Y-m-d'));
        $this->assertSame('7', $result->format('N'), 'El horizonte siempre cae en domingo.');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function horizonCases(): array
    {
        return [
            'jul→ago (día 1 sábado)' => ['2026-07', '2026-08-09'],
            'may→jun (día 1 lunes)' => ['2026-05', '2026-06-07'],
            'ago→sep (día 1 martes)' => ['2026-08', '2026-09-13'],
            'feb→mar (día 1 domingo)' => ['2026-02', '2026-03-08'],
        ];
    }

    /**
     * La rejilla de julio 2026 acaba con la primera semana completa de agosto
     * (3-9 ago, todos los días fuera del mes mostrado): así el reparto del 31-jul
     * tiene una fila de destino para posponerse una semana.
     */
    public function testGridExtendsToFirstFullWeekOfNextMonth(): void
    {
        /** @var list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: ?array, dropBasketId: ?int}>> $weeks */
        $weeks = $this->invokePrivate('buildMonthWeeks', 2026, 7, [], []);

        $this->assertNotEmpty($weeks);
        $lastWeek = $weeks[array_key_last($weeks)];
        $this->assertCount(7, $lastWeek);
        $this->assertSame('2026-08-03', $lastWeek[0]['date']->format('Y-m-d'), 'La última fila empieza el lunes 3-ago.');
        $this->assertSame('2026-08-09', $lastWeek[6]['date']->format('Y-m-d'), 'La última fila termina el domingo 9-ago.');

        foreach ($lastWeek as $day) {
            $this->assertFalse($day['inMonth'], 'Toda la última fila es del mes siguiente (atenuada en la UI).');
        }

        // El 31-jul (el borde) está dentro del grid y marcado como del mes mostrado.
        $this->assertTrue($this->gridHasDay($weeks, '2026-07-31', inMonth: true));
        // El primer viernes de reparto de agosto (destino natural de "mover una semana").
        $this->assertTrue($this->gridHasDay($weeks, '2026-08-07', inMonth: false));
    }

    /**
     * Un destino de arrastre situado en la primera semana del mes siguiente se
     * mapea a su celda del grid: confirma que un día de agosto puede ser soltable
     * desde la vista de julio (la fontanería de mover ya cruza de mes).
     */
    public function testDropTargetInNextMonthLandsOnItsCell(): void
    {
        $dropDays = ['2026-08-07' => 999];

        /** @var list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: ?array, dropBasketId: ?int}>> $weeks */
        $weeks = $this->invokePrivate('buildMonthWeeks', 2026, 7, [], $dropDays);

        $found = null;
        foreach ($weeks as $week) {
            foreach ($week as $day) {
                if ($day['date']->format('Y-m-d') === '2026-08-07') {
                    $found = $day;
                    break 2;
                }
            }
        }

        $this->assertNotNull($found, 'El 7-ago debe existir como celda del grid.');
        $this->assertSame(999, $found['dropBasketId'], 'La celda del mes siguiente recibe su destino de arrastre.');
    }

    /**
     * @param list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: ?array, dropBasketId: ?int}>> $weeks
     */
    private function gridHasDay(array $weeks, string $ymd, bool $inMonth): bool
    {
        foreach ($weeks as $week) {
            foreach ($week as $day) {
                if ($day['date']->format('Y-m-d') === $ymd && $day['inMonth'] === $inMonth) {
                    return true;
                }
            }
        }

        return false;
    }
}
