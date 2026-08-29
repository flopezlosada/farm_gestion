<?php

namespace App\Service\Volunteering;

/**
 * Lo que un socix lleva aportado en el periodo, y la referencia del colectivo.
 *
 * Existe para que la REGLA de qué se le enseña a quién viva en un solo sitio.
 * Estaba escrita a mano en el controlador del voluntariado, y en cuanto la
 * segunda pantalla —la home— quiso pintar lo mismo, ese `$median > 0 && $mine <
 * $median` iba a acabar copiado. Es justo la clase de condición que alguien
 * "simplifica" un martes sin saber lo que sostiene.
 *
 * LA REFERENCIA SÓLO SE LE ENSEÑA A QUIEN VA POR DEBAJO, y es la MEDIANA de
 * quienes participan, no la media. La media está contaminada por los ceros: con
 * mucha gente que no hace nada se hunde, y quien fue una tarde suelta sale "por
 * encima de la media" cuando hace falta bastante más. Y enseñársela a quien va
 * sobrado es peor: aprende que hace el doble de lo normal y se relaja hasta la
 * media. Es el efecto bumerán que documentaron Schultz y Cialdini con el consumo
 * eléctrico, donde los hogares que menos gastaban SUBIERON al ver la media del
 * barrio.
 *
 * QUIEN ESTÁ A CERO ES CASO APARTE, y por eso {@see hasStarted()} va separado en
 * vez de metido dentro de {@see showMedian()}: las dos pantallas no lo tratan
 * igual, y unificarlas cambiaría una de las dos en silencio.
 *
 * En la pantalla de voluntariado la referencia se le enseña también a quien no
 * ha empezado: allí el bloque va al final, en pequeño y después de todo lo que
 * hace falta, así que se lee como un dato ("quien echa una mano suele dedicar
 * unas 9 h al año"). En la HOME no: allí el bloque le da la bienvenida a todo el
 * mundo, y a quien entra por primera vez —o a cualquiera en enero, cuando el
 * periodo se reinicia y todo el mundo está a cero— una barra vacía con la marca
 * de la mediana le dice "vas 9 h por debajo" antes de haber tenido ocasión de
 * hacer nada. Eso es un reproche, y quien recibe un reproche al entrar deja de
 * entrar — y con él se pierde el canal por el que gestiona su cesta, que es el
 * activo de verdad.
 */
final class VolunteerContribution
{
    /**
     * @param int $minutes       minutos acreditados a este socix en el periodo
     * @param int $medianMinutes mediana de minutos entre quienes han participado
     */
    public function __construct(
        public readonly int $minutes,
        public readonly int $medianMinutes,
    ) {
    }

    /**
     * Si ha hecho algo ya en el periodo.
     *
     * @return bool true si tiene minutos acreditados
     */
    public function hasStarted(): bool
    {
        return $this->minutes > 0;
    }

    /**
     * Si se le puede enseñar la referencia del colectivo: sólo a quien va por
     * debajo. Ver el docblock de la clase antes de tocar esto — y ojo, quien está
     * a cero entra aquí; que se le enseñe o no lo decide cada pantalla con
     * {@see hasStarted()}.
     *
     * @return bool true si hay mediana y va por debajo de ella
     */
    public function showMedian(): bool
    {
        return $this->medianMinutes > 0 && $this->minutes < $this->medianMinutes;
    }
}
