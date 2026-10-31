<?php

namespace App\Service\Accounting\CashCount;

/**
 * Un arqueo que no se puede registrar tal como ha llegado (importe dudoso, fecha
 * imposible, caja que no existe o que no se sabe cuál es). El mensaje es para quien lo
 * mandó: dice qué falla y cómo escribirlo bien.
 */
final class CashCountRejected extends \RuntimeException
{
}
