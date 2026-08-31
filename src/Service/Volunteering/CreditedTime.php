<?php

namespace App\Service\Volunteering;

/**
 * La traducción entre lo que se guarda y lo que se escribe: MINUTOS por dentro,
 * HORAS en pantalla.
 *
 * Las dos mitades tienen su razón y no se pueden unificar sin perder algo.
 *
 * SE GUARDAN MINUTOS ENTEROS ({@see \App\Entity\VolunteerOffer::$creditedMinutes}):
 * un decimal de Doctrine vuelve del driver como string y acaba sumándose con
 * floats, y "media hora" es 30 sin ambigüedad. Un `float` de horas en la base de
 * datos daría 0.30000000000000004 antes o después.
 *
 * SE ESCRIBEN HORAS: nadie piensa "he estado 240 minutos" — piensa "cuatro
 * horas". Pedir minutos en el formulario obligaba a multiplicar mentalmente por
 * 60 a quien cierra una tarea con prisa, y ahí es donde salen los ceros de más.
 *
 * Vive en un sitio solo porque la conversión hace falta en tres: el formulario
 * de la tarea, el de anotar a alguien y el del cierre. Repartida, bastaría con
 * que uno redondeara distinto para que dos pantallas discreparan sobre lo que
 * vale el mismo trabajo.
 */
final class CreditedTime
{
    /**
     * Tope: un día. Más que eso es un dedo torcido, no una jornada.
     */
    public const MAX_MINUTES = 1440;

    /**
     * Los minutos que representa lo que alguien ha escrito en un campo de horas,
     * o null si lo dejó en blanco.
     *
     * EN BLANCO Y CERO SON COSAS DISTINTAS, y por eso no vale un `?:`. Cero es
     * una respuesta legítima —vino, pero esto no le computa— y convertirla en
     * null haría que se tomaran las horas de la tarea, justo lo contrario de lo
     * que se pidió.
     *
     * Acepta coma decimal además de punto: un `input[type=number]` normaliza a
     * punto, pero el mismo campo puede llegar de un formulario sin JavaScript o
     * de alguien que escribió "1,5" a mano, y rechazar la coma en castellano
     * sería rechazar la forma normal de escribirlo.
     *
     * @param mixed $raw lo que llegó del formulario
     *
     * @return int|null minutos, o null si el campo venía vacío
     */
    public static function minutesFromHours(mixed $raw): ?int
    {
        if (\is_array($raw)) {
            return null;
        }

        $text = trim((string) $raw);

        if ('' === $text) {
            return null;
        }

        $hours = (float) str_replace(',', '.', $text);

        return max(0, min(self::MAX_MINUTES, (int) round($hours * 60)));
    }

    /**
     * Las horas que se enseñan para unos minutos guardados, o null si no hay
     * nada guardado.
     *
     * @param int|null $minutes lo que vale el trabajo, en minutos
     *
     * @return float|null las mismas horas, o null
     */
    public static function hoursFromMinutes(?int $minutes): ?float
    {
        return null === $minutes ? null : round($minutes / 60, 2);
    }
}
