<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\Worker;
use PHPUnit\Framework\TestCase;

/**
 * La ficha de cesta de un trabajador sale de su ficha laboral: datos personales,
 * nombre partido en nombre y apellidos, y como no socia.
 */
class PartnerNonMemberForWorkerTest extends TestCase
{
    /** Con todos los datos, pasan todos y la ficha es de no socia. */
    public function testCopiesPersonalDataAsNonMember(): void
    {
        $worker = (new Worker())
            ->setName('Carmen Ruiz García')
            ->setNif('12345678z')
            ->setPhone('600 111 222')
            ->setEmail('carmen@example.org')
            ->setAddress('Calle Mayor 1, 28190 Patones');

        $partner = Partner::nonMemberFor($worker);

        $this->assertSame('Carmen', $partner->getname());
        $this->assertSame('Ruiz García', $partner->getSurname());
        $this->assertSame('12345678Z', $partner->getDNI());
        $this->assertSame('600 111 222', $partner->getcelular());
        $this->assertSame('carmen@example.org', $partner->getemail());
        $this->assertSame('Calle Mayor 1, 28190 Patones', $partner->getAddress());
        $this->assertFalse($partner->isMember());
        $this->assertNull($partner->getId(), 'No se guarda: la completa el formulario.');
    }

    /**
     * Las filas reales tienen sólo el nombre: sin apellido, DNI, teléfono, correo
     * ni dirección no revienta, deja esos campos vacíos para el formulario.
     *
     * @dataProvider onlyNames
     */
    public function testWorkerWithOnlyAName(string $workerName, string $name, ?string $surname): void
    {
        $partner = Partner::nonMemberFor((new Worker())->setName($workerName));

        $this->assertSame($name, $partner->getname());
        $this->assertSame($surname, $partner->getSurname());
        $this->assertNull($partner->getDNI());
        $this->assertNull($partner->getcelular());
        $this->assertNull($partner->getemail());
        $this->assertNull($partner->getAddress());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function onlyNames(): iterable
    {
        yield 'una palabra' => ['Sara', 'Sara', null];
        yield 'espacios alrededor' => ['  Sara Pérez  ', 'Sara', 'Pérez'];
        yield 'doble espacio en medio' => ['Sara  Pérez Gil', 'Sara', 'Pérez Gil'];
        yield 'compuesto: se ve y se corrige' => ['María José López', 'María', 'José López'];
    }
}
