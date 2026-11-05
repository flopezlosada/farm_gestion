<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Comprueba la letra de control de un DNI (8 cifras + letra) o NIE (X/Y/Z +
 * 7 cifras + letra; la X, Y y Z valen 0, 1 y 2 para el cálculo).
 */
class SpanishNifValidator extends ConstraintValidator
{
    /** Letra de control según el resto de dividir el número entre 23. */
    private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /**
     * @param mixed      $value
     * @param Constraint $constraint
     * @return void
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SpanishNif) {
            throw new UnexpectedTypeException($constraint, SpanishNif::class);
        }
        if ($value === null || $value === '') {
            return;
        }
        if (!self::isValid((string) $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    /**
     * ¿Es un DNI o NIE con la letra que le corresponde? Espera el valor ya en
     * mayúsculas y sin separadores.
     *
     * @param string $nif
     * @return bool
     */
    public static function isValid(string $nif): bool
    {
        if (preg_match('/^([XYZ]\d{7}|\d{8})([A-Z])$/', $nif, $m) !== 1) {
            return false;
        }
        $number = (int) strtr($m[1], ['X' => '0', 'Y' => '1', 'Z' => '2']);

        return self::LETTERS[$number % 23] === $m[2];
    }
}
