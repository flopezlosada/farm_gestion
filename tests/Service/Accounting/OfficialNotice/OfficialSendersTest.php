<?php

namespace App\Tests\Service\Accounting\OfficialNotice;

use App\Service\Accounting\OfficialNotice\OfficialSender;
use App\Service\Accounting\OfficialNotice\OfficialSenders;
use PHPUnit\Framework\TestCase;

/**
 * La lista de organismos de la configuración: cómo se reconoce el remitente y qué
 * dominio de firma vale para cada uno.
 */
class OfficialSendersTest extends TestCase
{
    /** La dirección se reconoce sin mirar mayúsculas ni espacios; una que no está, no. */
    public function testElRemitenteSeReconoceSinMirarMayusculas(): void
    {
        $senders = $this->senders();

        $this->assertSame('DEHú', $senders->find('NoReply.DEHU@Correo.Gob.ES ')?->agency);
        $this->assertSame('Agencia Tributaria', $senders->find('AgenciaTributaria@correo.aeat.es')?->agency);
        $this->assertNull($senders->find('facturas@proveedor.test'));
        $this->assertNull($senders->find(null), 'Un correo sin remitente no es de nadie.');
    }

    /** Vale el dominio de firma o un subdominio suyo; un dominio que sólo lo contiene, no. */
    public function testElDominioDeFirmaOUnSubdominio(): void
    {
        $aeat = $this->senders()->find('agenciatributaria@correo.aeat.es');

        $this->assertTrue($aeat->signs('aeat.es'));
        $this->assertTrue($aeat->signs('correo.aeat.es'));
        $this->assertTrue($aeat->signs('AEAT.ES.'), 'Mayúsculas y el punto final del DNS dan igual.');
        $this->assertFalse($aeat->signs('falsoaeat.es'));
        $this->assertFalse($aeat->signs('aeat.es.otro.test'));
        $this->assertFalse($aeat->signs(''));
        $this->assertFalse($aeat->signs('correo.gob.es'), 'La firma de otro organismo no vale.');
    }

    /** Un remitente sin dominio de firma daría por bueno cualquier correo que lo suplantara: no se admite. */
    public function testSinDominioDeFirmaNoArranca(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OfficialSenders(['noreply.dehu@correo.gob.es' => ['agency' => 'DEHú', 'dkim' => ['', ' ']]]);
    }

    /** Ni sin organismo. */
    public function testSinOrganismoNoArranca(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OfficialSender('noreply.dehu@correo.gob.es', ' ', ['correo.gob.es']);
    }

    /** La lista de la configuración, tal cual está en services.yaml. */
    private function senders(): OfficialSenders
    {
        return new OfficialSenders([
            'noreply.dehu@correo.gob.es' => ['agency' => 'DEHú', 'dkim' => ['correo.gob.es']],
            'agenciatributaria@correo.aeat.es' => ['agency' => 'Agencia Tributaria', 'dkim' => ['aeat.es', 'correo.aeat.es']],
            'notificaciones.telematicas.giss@seg-social.es' => ['agency' => 'Seguridad Social', 'dkim' => ['seg-social.es']],
        ]);
    }
}
