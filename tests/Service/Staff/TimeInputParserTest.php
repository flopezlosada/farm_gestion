<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\TimeInputParser;
use PHPUnit\Framework\TestCase;

/**
 * Parseo de fecha/hora de los formularios de fichaje. Importa sobre todo el
 * rechazo de horas fuera de rango: setTime(25, 61) desbordaría al día siguiente
 * sin error si no se validara.
 */
class TimeInputParserTest extends TestCase
{
    private TimeInputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TimeInputParser();
    }

    public function testComponeFechaYHoraEnMadrid(): void
    {
        $dt = $this->parser->composeDateTime('2026-06-21', '09:30');

        $this->assertNotNull($dt);
        $this->assertSame('2026-06-21 09:30:00', $dt->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Madrid', $dt->getTimezone()->getName());
    }

    /**
     * @dataProvider entradasInvalidas
     */
    public function testComposeDateTimeRechazaEntradasInvalidas(string $date, string $time): void
    {
        $this->assertNull($this->parser->composeDateTime($date, $time));
    }

    public static function entradasInvalidas(): array
    {
        return [
            'fecha vacía' => ['', '09:30'],
            'hora vacía' => ['2026-06-21', ''],
            'fecha desbordada' => ['2026-13-40', '09:30'],
            'hora desbordada' => ['2026-06-21', '25:61'],
            'basura' => ['xxx', 'yyy'],
        ];
    }

    public function testTimeOntoConservaElDiaYAplicaLaHora(): void
    {
        $base = new \DateTimeImmutable('2026-06-21 08:00:00', new \DateTimeZone('Europe/Madrid'));

        $result = $this->parser->timeOnto($base, '14:45');

        $this->assertNotNull($result);
        $this->assertSame('2026-06-21 14:45:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * @dataProvider horasFueraDeRango
     */
    public function testTimeOntoRechazaHorasFueraDeRango(string $time): void
    {
        $base = new \DateTimeImmutable('2026-06-21 08:00:00', new \DateTimeZone('Europe/Madrid'));

        $this->assertNull($this->parser->timeOnto($base, $time));
    }

    public static function horasFueraDeRango(): array
    {
        return [
            'hora > 23' => ['25:00'],
            'minutos > 59' => ['10:61'],
            'ambos desbordados' => ['99:99'],
            'formato no H:i' => ['9h30'],
            'vacío' => [''],
        ];
    }
}
