<?php

namespace App\Entity;

/**
 * Cómo va una semana la cesta de alguien que trabaja o trabajó en la asociación,
 * cuando no la paga como cualquier socix ({@see Worker::basketWeek()}).
 */
enum WorkerBasketWeek
{
    /** No se cobra: trabajaba esa semana, o se le regala tras la baja. */
    case Free;

    /** Ya no trabajaba y nadie ha decidido si la paga o se le regala. */
    case Undecided;
}
