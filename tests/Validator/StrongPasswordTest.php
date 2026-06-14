<?php

namespace App\Tests\Validator;

use App\Validator\StrongPassword;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Unit test de la política de contraseñas {@see StrongPassword}. Verifica que
 * acepta contraseñas con al menos 8 caracteres, una letra y un número, y que
 * rechaza las flojas que motivaron la regla (el "12345678" puro, "password"
 * sin número, y las demasiado cortas).
 */
class StrongPasswordTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidator();
    }

    /**
     * @dataProvider validPasswords
     */
    public function testAcceptsValidPasswords(string $password): void
    {
        $violations = $this->validator->validate($password, new StrongPassword());

        $this->assertCount(0, $violations, sprintf('"%s" debería ser válida', $password));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function validPasswords(): array
    {
        return [
            'letra y número, justo 8 caracteres' => ['abcdefg1'],
            'con mayúscula' => ['Abcd1234'],
            'frase larga con número' => ['mihuerta2026'],
            'unicode (acento) más número' => ['contraseña9'],
        ];
    }

    /**
     * @dataProvider weakPasswords
     */
    public function testRejectsWeakPasswords(string $password): void
    {
        $violations = $this->validator->validate($password, new StrongPassword());

        $this->assertGreaterThan(
            0,
            count($violations),
            sprintf('"%s" debería rechazarse', $password)
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'solo dígitos (el clásico 12345678)' => ['12345678'],
            'solo letras, sin número' => ['password'],
            'corta aunque tenga letra y número' => ['abc1234'],
            'vacía' => [''],
        ];
    }
}
