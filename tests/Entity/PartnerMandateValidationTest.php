<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * El mandato de la ficha va entero (referencia y fecha, con cuenta), y la cuenta tiene
 * que ser un IBAN válido. Pero sólo lo exige el formulario de gestión (grupo
 * `billing`): el perfil que edita el propio socix no enseña esos campos, y un IBAN
 * viejo mal escrito no puede impedirle guardar su teléfono.
 */
class PartnerMandateValidationTest extends KernelTestCase
{
    /** @return list<string> Las rutas de las propiedades con error. */
    private function errors(Partner $partner, array $groups): array
    {
        self::bootKernel();
        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($partner, null, $groups);

        return array_values(array_unique(array_map(
            static fn (ConstraintViolationInterface $v): string => $v->getPropertyPath(),
            iterator_to_array($violations),
        )));
    }

    public function testElMandatoVaEntero(): void
    {
        $sinFecha = (new Partner())->setIban('ES9121000418450200051332')->setMandateReference('CUOTA MENSUAL 99901');
        $sinReferencia = (new Partner())->setIban('ES9121000418450200051332')->setMandateSignedOn(new \DateTimeImmutable('2026-10-01'));
        $sinCuenta = (new Partner())->setMandateReference('CUOTA MENSUAL 99902')->setMandateSignedOn(new \DateTimeImmutable('2026-10-01'));
        $completo = (new Partner())->setIban('ES9121000418450200051332')->setMandateReference('CUOTA MENSUAL 99903')->setMandateSignedOn(new \DateTimeImmutable('2026-10-01'));

        $this->assertContains('mandateSignedOn', $this->errors($sinFecha, ['billing']));
        $this->assertContains('mandateReference', $this->errors($sinReferencia, ['billing']));
        $this->assertContains('iban', $this->errors($sinCuenta, ['billing']));
        $this->assertSame([], $this->errors($completo, ['billing']));
        $this->assertTrue($completo->hasDirectDebit());
        $this->assertFalse($sinFecha->hasDirectDebit());
    }

    public function testUnIbanMalEscritoSoloLoParaElFormularioDeGestion(): void
    {
        $partner = (new Partner())->setIban('ES9121000418450200051333');

        $this->assertContains('iban', $this->errors($partner, ['billing']));
        $this->assertNotContains('iban', $this->errors($partner, ['Default']), 'El perfil del socix valida sólo Default.');
    }
}
