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
 * ESTAR A CERO SÍ SE DICE, salvo a quien acaba de llegar. Un cero callado no
 * moviliza a nadie: quien lleva media temporada sin haber echado una mano y ve
 * una bienvenida entiende que no hace falta. La excepción es quien todavía no ha
 * tenido ocasión —ver {@see hasHadNoChance()}—, porque a esa persona el cero no
 * le informa de nada que dependa de ella.
 *
 * En enero no hace falta regla ninguna: cuando el periodo se reinicia y todo el
 * mundo está a cero, la mediana también es 0 y {@see showMedian()} se apaga sola.
 */
final class VolunteerContribution
{
    /**
     * @param int  $minutes       minutos acreditados a este socix en el periodo
     * @param int  $medianMinutes mediana de minutos entre quienes han participado
     * @param bool $isNewcomer    si acaba de entrar en la asociación
     *                            ({@see VolunteerContributions::NEWCOMER_DAYS}).
     *                            Por defecto SÍ: quien construya esto sin pensarlo
     *                            se queda en el lado que no riñe a nadie, que es
     *                            el error barato de los dos.
     */
    public function __construct(
        public readonly int $minutes,
        public readonly int $medianMinutes,
        public readonly bool $isNewcomer = true,
    ) {
    }

    /**
     * Si todavía no ha tenido ocasión de echar una mano: está a cero y acaba de
     * entrar. Es el único caso en el que el cero no se le enseña.
     *
     * A quien recibe un reproche el primer día se le pierde el canal por el que
     * gestiona su cesta, que es el activo de verdad; y el cero de alguien que
     * lleva tres semanas no dice nada de esa persona, sólo dice que aún no ha
     * pasado nada.
     *
     * @return bool true si toca darle la bienvenida en vez del dato
     */
    public function hasHadNoChance(): bool
    {
        return !$this->hasStarted() && $this->isNewcomer;
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
