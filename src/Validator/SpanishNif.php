<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * DNI o NIE español con su letra de control bien calculada. Un DNI mal tecleado
 * (un dígito cambiado) casi nunca conserva la letra, así que la letra pilla la
 * errata antes de que llegue a un documento oficial como el registro de jornada.
 *
 * Valida el valor ya normalizado (mayúsculas, sin espacios ni guiones): quien
 * guarda el dato lo normaliza antes. Null o vacío pasa: la obligatoriedad, si la
 * hay, la pone otra regla.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class SpanishNif extends Constraint
{
    public string $message = 'Ese DNI/NIE no es válido: revisa los números y la letra.';
}
