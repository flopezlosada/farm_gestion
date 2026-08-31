<?php

namespace App\Tests\Service\Partner;

use App\Entity\City;
use App\Entity\Partner;
use App\Entity\State;
use App\Service\Partner\PartnerProfileCompleteness;
use PHPUnit\Framework\TestCase;

/**
 * Qué se considera una ficha incompleta.
 *
 * Es la definición de la que cuelgan cuatro pantallas (el aviso al socix, el aviso
 * a quien coordina, el listado de gestión y "Mis datos"), así que lo que se fija
 * aquí es exactamente lo que verán todas. Dos cosas por encima del resto:
 *
 *  1. AL SOCIX SÓLO SE LE PIDE LO QUE PUEDE ARREGLAR. Su formulario no toca el
 *     teléfono ni el correo, así que si sólo le falta uno de ésos no hay nada que
 *     decirle: el aviso sería imposible de cerrar y le volvería cada semana
 *     durante años. Es el fallo que este test existe para que no vuelva.
 *  2. LAS FICHAS DE FAMILIARES NO CUENTAN. Comparten dirección, teléfono y correo
 *     con la principal y las llevan vacías POR DISEÑO; contarlas señalaría como
 *     problema justo lo que el modelo hace a propósito, y a la coordinadora le
 *     saldría una lista imposible de vaciar.
 */
class PartnerProfileCompletenessTest extends TestCase
{
    public function testUnaFichaCompletaNoLeFaltaNada(): void
    {
        $completeness = new PartnerProfileCompleteness();

        self::assertSame([], $completeness->missing($this->complete()));
        self::assertFalse($completeness->isIncomplete($this->complete()));
    }

    public function testDiceLoQueFaltaConSuNombre(): void
    {
        $partner = $this->complete()->setDNI(null)->setAddress(null);

        self::assertSame(['DNI', 'dirección'], (new PartnerProfileCompleteness())->missing($partner));
    }

    /**
     * Un espacio suelto no es un DNI. El dump de producción trae campos así, y sin
     * el trim la ficha pasaría por completa con el hueco puesto.
     */
    public function testUnCampoConSoloEspaciosCuentaComoVacio(): void
    {
        $partner = $this->complete()->setDNI('   ');

        self::assertSame(['DNI'], (new PartnerProfileCompleteness())->missing($partner));
    }

    /**
     * EL CASO QUE DA SENTIDO A LA CLASE: a quien sólo le falta el teléfono se le ve
     * en el listado de gestión, pero NO se le avisa, porque no tiene dónde
     * cambiarlo.
     */
    public function testAlSocixNoSeLePideLoQueSoloCambiaAdministracion(): void
    {
        $completeness = new PartnerProfileCompleteness();
        $partner = $this->complete()->setcelular(null)->setemail('');

        self::assertSame(['teléfono', 'correo electrónico'], $completeness->missing($partner));
        self::assertSame([], $completeness->missingSelfService($partner), 'No hay nada que él pueda rellenar.');
    }

    public function testAlSocixSeLePideSoloSuParteCuandoFaltanLasDos(): void
    {
        $completeness = new PartnerProfileCompleteness();
        $partner = $this->complete()->setDNI(null)->setcelular(null);

        self::assertSame(['DNI', 'teléfono'], $completeness->missing($partner));
        self::assertSame(['DNI'], $completeness->missingSelfService($partner));
    }

    /**
     * @dataProvider estadosQueNoSePersiguen
     */
    public function testNoSePersigueLaFichaDeQuienNoEstaActivx(string $status): void
    {
        $partner = $this->complete()->setStatus($status);

        self::assertFalse((new PartnerProfileCompleteness())->applies($partner));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function estadosQueNoSePersiguen(): iterable
    {
        yield 'de baja' => [Partner::STATUS_BAJA];
        yield 'en pausa' => [Partner::STATUS_PAUSADO];
    }

    public function testSiSePersigueLaFichaDeQuienEstaActivx(): void
    {
        self::assertTrue((new PartnerProfileCompleteness())->applies($this->complete()));
    }

    public function testLaFichaDeUnFamiliarNoSePersigue(): void
    {
        $familiar = $this->complete()->setParent($this->complete());

        self::assertFalse(
            (new PartnerProfileCompleteness())->applies($familiar),
            'Un familiar comparte dirección, teléfono y correo con la ficha principal: los lleva vacíos a propósito.',
        );
    }

    /**
     * Una ficha con todos los datos importantes puestos.
     *
     * @return Partner el socix
     */
    private function complete(): Partner
    {
        // setname/setemail/setcelular en minúscula: es como los declara la
        // entidad legacy. PHP no distingue mayúsculas en los nombres de método,
        // pero se escriben como están para que buscarlos funcione.
        return (new Partner())
            ->setname('Eros')
            ->setSurname('García Pérez')
            ->setDNI('12345678Z')
            ->setAddress('Calle de la Huerta, 1')
            ->setState(new State())
            ->setCity(new City())
            ->setcelular('600123456')
            ->setemail('eros@csavega.local');
    }
}
