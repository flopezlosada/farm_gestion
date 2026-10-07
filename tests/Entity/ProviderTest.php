<?php

namespace App\Tests\Entity;

use App\Entity\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas del proveedor que dependen de él solo: cómo se compara un CIF y que los
 * datos de una factura completan la ficha sin pisar lo que ya tiene.
 */
class ProviderTest extends TestCase
{
    /** El CIF se guarda como se compara: mayúsculas, sin espacios, guiones ni puntos. */
    public function testElCifSeNormaliza(): void
    {
        $this->assertSame('B86850963', (new Provider())->setTaxId(' b-86.850 963 ')->getTaxId());
        $this->assertNull((new Provider())->setTaxId(' - ')->getTaxId(), 'Un CIF sin letras ni cifras es que no hay CIF.');
        $this->assertNull(Provider::normalizeTaxId(null));
    }

    /**
     * El NIF-IVA intracomunitario («ES» delante) es el mismo CIF: si no se igualan, un
     * proveedor que a veces lo escribe así y a veces no saldría dos veces.
     */
    public function testElPrefijoEsDelNifIvaSeQuita(): void
    {
        $this->assertSame('B84283902', Provider::normalizeTaxId('ESB84283902'));
        $this->assertSame('05427289W', Provider::normalizeTaxId('ES-05427289-W'));
        $this->assertSame('ESTONOESUNCIF', Provider::normalizeTaxId('es tono es un cif'), 'Sólo se quita delante de algo con forma de CIF.');
    }

    /** El IBAN se guarda sin espacios, que es como lo pide un fichero de transferencias. */
    public function testElIbanSeGuardaSinEspacios(): void
    {
        $this->assertSame('ES9121000418450200051332', (new Provider())->setIban('es91 2100 0418 4502 0005 1332')->getIban());
        $this->assertNull((new Provider())->setIban('  ')->getIban());
    }

    /**
     * Una factura completa los huecos y nada más. Un campo con sólo espacios cuenta
     * como hueco: es lo que deja un formulario mal rellenado.
     */
    public function testUnaFacturaCompletaLosHuecosSinPisarNada(): void
    {
        $provider = (new Provider())->setName('Ferretería')->setAddress('Plaza Mayor 1')->setTown('   ');

        $provider->fillBlanks('Polígono Sur, nave 3', '28180', 'Torrelaguna', null);

        $this->assertSame('Plaza Mayor 1', $provider->getAddress(), 'La dirección que ya tenía no se pisa.');
        $this->assertSame('28180', $provider->getPostalCode());
        $this->assertSame('Torrelaguna', $provider->getTown(), 'Un campo en blanco cuenta como hueco.');
        $this->assertNull($provider->getProvince(), 'Si la factura no trae el dato, el hueco sigue siéndolo.');
    }
}
