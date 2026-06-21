<?php

namespace App\Service\Staff;

/**
 * Genera los festivos oficiales HABITUALES de un año para la Comunidad de Madrid:
 * los nacionales fijos + los de Semana Santa (Jueves y Viernes Santo, calculados)
 * + el 2 de mayo (Día de la Comunidad de Madrid).
 *
 * NO es la lista oficial cerrada: el calendario laboral lo publica cada año el
 * BOE/Comunidad y puede trasladar algún festivo (p. ej. si cae en domingo) o
 * cambiar las fiestas sustituibles. Sirve como PLANTILLA que el supervisor revisa;
 * los DOS festivos LOCALES del municipio (aquí Torremocha de Jarama) se añaden a
 * mano, no salen de aquí.
 *
 * La Pascua se calcula con el algoritmo de Computus (Meeus/Jones/Butcher), en PHP
 * puro: no usa easter_date() para no depender de la extensión calendar, que puede
 * faltar en CI o en el hosting.
 */
class SpanishHolidayCalendar
{
    /**
     * Festivos habituales del año (Madrid), indexados por fecha 'Y-m-d'.
     *
     * @param int $year Año.
     * @return array<string, string> Nombre del festivo por fecha 'Y-m-d'.
     */
    public function forYear(int $year): array
    {
        $holidays = [
            sprintf('%04d-01-01', $year) => 'Año Nuevo',
            sprintf('%04d-01-06', $year) => 'Epifanía del Señor',
            sprintf('%04d-05-01', $year) => 'Fiesta del Trabajo',
            sprintf('%04d-05-02', $year) => 'Día de la Comunidad de Madrid',
            sprintf('%04d-08-15', $year) => 'Asunción de la Virgen',
            sprintf('%04d-10-12', $year) => 'Fiesta Nacional de España',
            sprintf('%04d-11-01', $year) => 'Todos los Santos',
            sprintf('%04d-12-06', $year) => 'Día de la Constitución',
            sprintf('%04d-12-08', $year) => 'Inmaculada Concepción',
            sprintf('%04d-12-25', $year) => 'Natividad del Señor',
        ];

        $easter = $this->easterSunday($year);
        $holidays[$easter->modify('-3 days')->format('Y-m-d')] = 'Jueves Santo';
        $holidays[$easter->modify('-2 days')->format('Y-m-d')] = 'Viernes Santo';

        ksort($holidays);

        return $holidays;
    }

    /**
     * Domingo de Pascua del año (calendario gregoriano), por el algoritmo de
     * Computus. Lógica pura, sin extensiones.
     *
     * @param int $year Año.
     * @return \DateTimeImmutable Medianoche del Domingo de Pascua.
     */
    private function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new \DateTimeZone('Europe/Madrid'));
    }
}
