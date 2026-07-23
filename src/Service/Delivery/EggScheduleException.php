<?php

namespace App\Service\Delivery;

/**
 * Error de validación al editar el calendario de HUEVOS de un socio (mover / no recoger).
 * El mensaje es apto para mostrar directamente al usuario (gestor o socio); el controlador
 * lo pinta como flash. Neutro en tono para servir a ambas pantallas.
 */
class EggScheduleException extends \RuntimeException
{
}
