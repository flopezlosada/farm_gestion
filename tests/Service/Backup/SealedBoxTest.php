<?php

namespace App\Tests\Service\Backup;

use App\Service\Backup\SealedBox;
use PHPUnit\Framework\TestCase;

/**
 * El cifrado de las copias: lo que sale del servidor sólo lo abre la clave
 * privada, y la privada basta para abrirlo (la pública se deriva).
 */
class SealedBoxTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_box_keypair();
        $this->publicKey = base64_encode(sodium_crypto_box_publickey($keyPair));
        $this->secretKey = base64_encode(sodium_crypto_box_secretkey($keyPair));
    }

    public function testLaClavePrivadaSolaAbreLoQueCerroLaPublica(): void
    {
        $sealed = (new SealedBox($this->publicKey))->seal('libro de caja');

        $this->assertStringNotContainsString('libro de caja', $sealed);
        $this->assertSame('libro de caja', SealedBox::open($sealed, $this->secretKey));
    }

    /** Copiada de un gestor de contraseñas, la clave suele traer un salto de línea. */
    public function testLasClavesConEspaciosAlrededorValen(): void
    {
        $sealed = (new SealedBox(" {$this->publicKey}\n"))->seal('x');

        $this->assertSame('x', SealedBox::open($sealed, "{$this->secretKey}\n"));
    }

    public function testOtraClavePrivadaNoAbreLaCopia(): void
    {
        $sealed = (new SealedBox($this->publicKey))->seal('x');
        $other = base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()));

        $this->expectExceptionMessage('no es la de esta copia');
        SealedBox::open($sealed, $other);
    }

    public function testUnaClaveQueNoEsBase64DeTreintaYDosBytesSeRechaza(): void
    {
        $this->expectExceptionMessage('La clave pública no es válida');
        (new SealedBox(base64_encode('corta')))->seal('x');
    }

    public function testSinClavePublicaLaCopiaEstaApagada(): void
    {
        $this->assertFalse((new SealedBox('  '))->isConfigured());
        $this->assertTrue((new SealedBox($this->publicKey))->isConfigured());
    }
}
