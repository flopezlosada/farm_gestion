<?php

namespace App\Service\Accounting\Agenda;

/**
 * Cuánto aprieta una tarea de la portada de contabilidad. El orden de los casos es el
 * orden en que se enseñan: lo que tiene fecha y se acerca, antes que lo que sólo
 * conviene no dejar crecer.
 */
enum AgendaUrgency: int
{
    /** Tiene un plazo y quedan pocos días. */
    case Soon = 1;

    /** Hay que hacerlo, sin fecha que apriete. */
    case Normal = 2;
}
