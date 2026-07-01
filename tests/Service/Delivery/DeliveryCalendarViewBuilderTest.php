<?php

namespace App\Tests\Service\Delivery;

use App\Service\Delivery\DeliveryCalendarViewBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test unitario de la pieza PURA de la rejilla del calendario de recogida: buildMonthWeeks
 * dibuja de $gridStart a $gridEnd en filas de 7, marca `inMonth` y coloca los destinos de
 * arrastre en su celda.
 *
 * El cálculo del horizonte en sí (gridHorizon) se ancla a los repartos del NODO y consulta
 * BBDD + NodeDeliveryDate, así que se valida a nivel funcional/manual, no aquí. buildMonthWeeks
 * no toca dependencias, así que se instancia sin constructor.
 */
class DeliveryCalendarViewBuilderTest extends TestCase
{
    /**
     * Los métodos bajo prueba son lógica pura de fechas y no tocan las dependencias
     * inyectadas (proyector / generador / EM / NodeDeliveryDate), así que se instancia sin
     * constructor: basta con tener un objeto sobre el que invocarlos.
     */
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $builder = (new \ReflectionClass(DeliveryCalendarViewBuilder::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionMethod(DeliveryCalendarViewBuilder::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($builder, ...$args);
    }

    /**
     * buildMonthWeeks dibuja de $gridStart a $gridEnd en filas de 7, marca `inMonth` solo
     * las celdas del mes mostrado, y coloca los destinos de arrastre en su celda física —
     * incluida una celda de un mes vecino (una entrega movida cruzando de mes se dibuja ahí).
     */
    public function testBuildMonthWeeksHonoursRangeMonthFlagAndDropTargets(): void
    {
        $gridStart = new \DateTimeImmutable('2026-07-27'); // lunes
        $gridEnd = new \DateTimeImmutable('2026-09-13');   // domingo
        $dropDays = ['2026-09-11' => 777];

        /** @var list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: ?array, dropBasketId: ?int}>> $weeks */
        $weeks = $this->invokePrivate('buildMonthWeeks', 2026, 8, $gridStart, $gridEnd, [], $dropDays);

        $this->assertNotEmpty($weeks);
        $this->assertSame('2026-07-27', $weeks[0][0]['date']->format('Y-m-d'), 'Arranca en el lunes del rango.');
        $lastWeek = $weeks[array_key_last($weeks)];
        $this->assertSame('2026-09-13', $lastWeek[6]['date']->format('Y-m-d'), 'Termina en el domingo del rango.');

        // El 31-jul es del mes anterior (no inMonth); el 1-ago sí; el 11-sep no y lleva su destino.
        $this->assertTrue($this->gridHasDay($weeks, '2026-07-31', inMonth: false));
        $this->assertTrue($this->gridHasDay($weeks, '2026-08-01', inMonth: true));

        $found = null;
        foreach ($weeks as $week) {
            foreach ($week as $day) {
                if ($day['date']->format('Y-m-d') === '2026-09-11') {
                    $found = $day;
                    break 2;
                }
            }
        }
        $this->assertNotNull($found, 'El 11-sep debe existir como celda del grid.');
        $this->assertFalse($found['inMonth'], 'El 11-sep es del mes siguiente (atenuado en la UI).');
        $this->assertSame(777, $found['dropBasketId'], 'La celda del mes vecino recibe su destino de arrastre.');
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
