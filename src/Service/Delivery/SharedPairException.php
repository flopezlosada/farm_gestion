<?php

namespace App\Service\Delivery;

/**
 * Un cambio sobre una cesta compartida que no se puede aplicar. El mensaje está
 * escrito para leerse tal cual en pantalla —lo pintan como flash el calendario del
 * gestor y el panel del socix—, así que no lleva ids ni jerga interna.
 *
 * Mismo papel que {@see EggScheduleException} en la edición de huevos: separa el
 * "esto no se puede" del dominio de los fallos técnicos, que siguen subiendo como
 * excepción normal.
 */
final class SharedPairException extends \RuntimeException
{
}
