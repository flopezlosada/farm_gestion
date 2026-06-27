<?php

namespace App\Service\Staff;

/**
 * Se lanza cuando un fichaje que se intenta añadir o corregir rompería el orden
 * entrada-salida del día (dos del mismo tipo seguidos, una salida sin entrada,
 * etc.). El mensaje es apto para mostrarse al usuario.
 */
class PunchSequenceException extends \DomainException
{
}
