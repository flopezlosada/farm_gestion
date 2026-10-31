<?php

namespace App\Tests\Service\Accounting\OfficialNotice;

use App\Service\Accounting\OfficialNotice\AuthenticationResults;
use PHPUnit\Framework\TestCase;

/**
 * Qué firmas DKIM da por válidas una cabecera Authentication-Results, con las formas
 * en que la escriben los servidores de verdad (comentarios, plegada en varias líneas,
 * sólo con header.i, varias firmas).
 */
class AuthenticationResultsTest extends TestCase
{
    /** La forma habitual: dominio de header.d, en minúsculas, con su comentario quitado. */
    public function testFirmaValidaConComentario(): void
    {
        $this->assertSame(['correo.gob.es'], AuthenticationResults::dkimPassDomains(
            'mx.buzon.test; dkim=pass (2048-bit key; unprotected) header.d=Correo.Gob.ES header.i=@correo.gob.es header.s=sel1 header.b=AbCd; spf=pass smtp.mailfrom=correo.gob.es; dmarc=pass header.from=correo.gob.es',
        ));
    }

    /** Plegada en varias líneas, como llega del servidor. */
    public function testCabeceraPlegada(): void
    {
        $this->assertSame(['seg-social.es'], AuthenticationResults::dkimPassDomains(
            "mx.buzon.test;\r\n\tdkim=pass\r\n\theader.d=seg-social.es header.s=s1;\r\n\tspf=softfail smtp.mailfrom=seg-social.es",
        ));
    }

    /** Hay servidores que sólo ponen header.i: vale el dominio de detrás de la arroba. */
    public function testSoloHeaderI(): void
    {
        $this->assertSame(['correo.aeat.es'], AuthenticationResults::dkimPassDomains('mx.buzon.test; dkim=pass header.i=@correo.aeat.es'));
    }

    /** Sólo cuentan las firmas que pasan; una fallida o neutra no da dominio. */
    public function testSoloCuentanLasQuePasan(): void
    {
        $this->assertSame(['otro.test'], AuthenticationResults::dkimPassDomains(
            'mx.buzon.test; dkim=fail (bad signature) header.d=correo.gob.es; dkim=neutral header.d=aeat.es; dkim=pass header.d=otro.test',
        ));
    }

    /** Un «dkim=pass» escrito en un comentario no es un resultado. */
    public function testUnComentarioNoEsUnResultado(): void
    {
        $this->assertSame([], AuthenticationResults::dkimPassDomains(
            'mx.buzon.test; dkim=none (dkim=pass header.d=correo.gob.es) header.d=correo.gob.es',
        ));
    }

    /** El identificador del servidor va delante del primer «;»: no es un resultado aunque lo parezca. */
    public function testElIdentificadorDelServidorNoCuenta(): void
    {
        $this->assertSame([], AuthenticationResults::dkimPassDomains('dkim=pass header.d=correo.gob.es'));
    }

    /** Sin cabecera, vacía o sin firmas: ningún dominio. */
    public function testSinCabeceraNoHayFirmas(): void
    {
        $this->assertSame([], AuthenticationResults::dkimPassDomains(null));
        $this->assertSame([], AuthenticationResults::dkimPassDomains(''));
        $this->assertSame([], AuthenticationResults::dkimPassDomains('mx.buzon.test; none'));
        $this->assertSame([], AuthenticationResults::dkimPassDomains('mx.buzon.test; dkim=pass'), 'Una firma que no dice de quién no prueba nada.');
    }
}
