<?php

namespace App\Service\Backup;

/**
 * Backblaze B2 no ha hecho lo pedido. El mensaje se puede enseñar: nunca lleva la
 * clave ni encadena el error del cliente HTTP.
 */
class B2Exception extends \RuntimeException
{
}
