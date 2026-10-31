<?php

namespace App\Service\Accounting\Norma43;

/**
 * Un extracto Norma 43 que no se puede aceptar. El mensaje es para la persona que lo
 * sube: dice en qué línea está el fallo y qué esperaba, en castellano llano. Si salta,
 * no se guarda NADA del fichero.
 */
final class Norma43Exception extends \RuntimeException
{
}
