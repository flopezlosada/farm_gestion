<?php

namespace App\Tests\Service\Backup;

use App\Service\Backup\B2Client;
use App\Service\Backup\B2Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La subida a B2: que el fichero va bloqueado, que una clave que puede borrar no
 * sube nada y que los errores no sacan la clave.
 */
class B2ClientTest extends TestCase
{
    private const KEY_ID = '0030000000000000000000009';

    private const APP_KEY = 'K003SECRETO';

    private const AUTHORIZE_URL = 'https://api.backblazeb2.com/b2api/v4/b2_authorize_account';

    private const API_URL = 'https://api003.backblazeb2.com';

    private const UPLOAD_URL = 'https://pod-000-1000-00.backblaze.com/b2api/v4/b2_upload_file/bucket1/c003';

    public function testSubeElFicheroBloqueadoEnGovernanceHastaLaFecha(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            $this->authorized(['listFiles', 'writeFiles', 'writeFileRetentions']),
            new MockResponse(json_encode(['uploadUrl' => self::UPLOAD_URL, 'authorizationToken' => 'upload-token'])),
            new MockResponse(json_encode(['fileId' => '4_z123', 'fileName' => 'prueba/a b.sealed'])),
        ]);
        $until = new \DateTimeImmutable('2026-10-19 03:00:00 UTC');

        $file = $client->uploadLocked('prueba/a b.sealed', 'cifrado', $until);

        $this->assertSame(['fileId' => '4_z123', 'fileName' => 'prueba/a b.sealed', 'bucket' => 'bucket-de-copias'], $file);

        [$authorize, $uploadUrl, $upload] = $requests;
        $this->assertStringStartsWith('Authorization: Basic ' . base64_encode(self::KEY_ID . ':' . self::APP_KEY), $this->header($authorize, 'authorization'));
        $this->assertSame(self::API_URL . '/b2api/v4/b2_get_upload_url?bucketId=bucket1', $uploadUrl['url']);
        $this->assertSame(self::UPLOAD_URL, $upload['url']);
        $this->assertSame('X-Bz-File-Name: prueba/a%20b.sealed', $this->header($upload, 'x-bz-file-name'));
        $this->assertSame('X-Bz-Content-Sha1: ' . sha1('cifrado'), $this->header($upload, 'x-bz-content-sha1'));
        $this->assertSame('X-Bz-File-Retention-Mode: governance', $this->header($upload, 'x-bz-file-retention-mode'));
        $this->assertSame('X-Bz-File-Retention-Retain-Until-Timestamp: ' . $until->getTimestamp() * 1000, $this->header($upload, 'x-bz-file-retention-retain-until-timestamp'));
        $this->assertSame('Authorization: upload-token', $this->header($upload, 'authorization'));
    }

    /**
     * La primera copia sube más de mil ficheros: se entra en B2 y se pide URL de
     * subida una sola vez, no una por fichero.
     */
    public function testVariasSubidasEntranUnaVezYReutilizanLaUrl(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            $this->authorized(['listFiles', 'writeFiles', 'writeFileRetentions']),
            $this->uploadUrl(),
            $this->uploaded('a'),
            $this->uploaded('b'),
        ]);

        $client->uploadLocked('a', 'x', new \DateTimeImmutable('+1 day'));
        $client->uploadLocked('b', 'y', new \DateTimeImmutable('+1 day'));

        $this->assertSame([self::AUTHORIZE_URL, self::API_URL . '/b2api/v4/b2_get_upload_url?bucketId=bucket1', self::UPLOAD_URL, self::UPLOAD_URL], array_column($requests, 'url'));
    }

    /** Si el servidor de subida falla, se pide otra URL y se reintenta una vez. */
    public function testSiFallaLaSubidaReintentaConOtraUrl(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            $this->authorized(['writeFiles']),
            $this->uploadUrl(),
            new MockResponse('{"status":503,"code":"service_unavailable","message":"c001 is too busy"}', ['http_code' => 503]),
            $this->uploadUrl(),
            $this->uploaded('a'),
        ]);

        $file = $client->uploadLocked('a', 'x', new \DateTimeImmutable('+1 day'));

        $this->assertSame('4_za', $file['fileId']);
        $this->assertCount(5, $requests);
    }

    /** Un rechazo que se repetiría (400, 403) no vuelve a subir el fichero entero. */
    public function testUnRechazoPermanenteNoSeReintenta(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            $this->authorized(['writeFiles']),
            $this->uploadUrl(),
            new MockResponse('{"status":400,"code":"bad_request","message":"File names must not contain DEL"}', ['http_code' => 400]),
        ]);

        try {
            $client->uploadLocked('a', 'x', new \DateTimeImmutable('+1 day'));
            $this->fail('Debía fallar.');
        } catch (B2Exception $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertCount(3, $requests);
    }

    /** Lo que se pide guardar con el fichero viaja en cabeceras X-Bz-Info-. */
    public function testLosDatosPropiosVanConElFichero(): void
    {
        $requests = [];
        $client = $this->client($requests, [$this->authorized(['writeFiles']), $this->uploadUrl(), $this->uploaded('a')]);

        $client->uploadLocked('a', 'x', new \DateTimeImmutable('+1 day'), ['plain_sha1' => 'abc123']);

        $this->assertSame('X-Bz-Info-plain_sha1: abc123', $this->header($requests[2], 'x-bz-info-plain_sha1'));
    }

    /** El listado recorre todas las páginas y devuelve los datos propios de cada fichero. */
    public function testListaTodasLasPaginas(): void
    {
        $requests = [];
        $client = $this->client($requests, [
            $this->authorized(['listFiles']),
            new MockResponse(json_encode([
                'files' => [['fileName' => 'web/documentos/a.pdf.sealed', 'fileInfo' => ['plain_sha1' => '111']]],
                'nextFileName' => 'web/documentos/b.pdf.sealed',
            ])),
            new MockResponse(json_encode([
                'files' => [['fileName' => 'web/documentos/b.pdf.sealed', 'fileInfo' => []]],
                'nextFileName' => null,
            ])),
        ]);

        $files = $client->listFiles('web/documentos/');

        $this->assertSame(['web/documentos/a.pdf.sealed' => ['plain_sha1' => '111'], 'web/documentos/b.pdf.sealed' => []], $files);
        $this->assertStringContainsString('prefix=web/documentos/', $requests[1]['url']);
        $this->assertStringNotContainsString('startFileName', $requests[1]['url']);
        $this->assertStringContainsString('startFileName=web/documentos/b.pdf.sealed', $requests[2]['url']);
    }

    /**
     * Si la clave del servidor puede borrar, quien entre en el hosting puede
     * llevarse las copias por delante: no se sube nada y se dice qué sobra.
     */
    public function testUnaClaveQuePuedeBorrarNoSubeNada(): void
    {
        $requests = [];
        $client = $this->client($requests, [$this->authorized(['listFiles', 'writeFiles', 'deleteFiles', 'bypassGovernance'])]);

        try {
            $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
            $this->fail('Debía negarse.');
        } catch (B2Exception $e) {
            $this->assertStringContainsString('deleteFiles, bypassGovernance', $e->getMessage());
        }
        $this->assertCount(1, $requests);
    }

    public function testUnaClaveSinRestringirAUnBucketNoSubeNada(): void
    {
        $requests = [];
        $client = $this->client($requests, [$this->authorized(['writeFiles'], [])]);

        $this->expectExceptionMessage('restringida a un único bucket');
        $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
    }

    public function testElMotivoQueDaB2LlegaALaSalida(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse(
            '{"status":401,"code":"bad_auth_token","message":"Invalid accountId or applicationKeyId"}',
            ['http_code' => 401],
        )]);

        $this->expectExceptionMessage('Backblaze B2 contesta 401 en b2_authorize_account: bad_auth_token — Invalid accountId or applicationKeyId');
        $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
    }

    /** Un error en HTML (un proxy, un balanceador) no se confunde con un corte de conexión. */
    public function testUnErrorSinJsonDiceElCodigo(): void
    {
        $requests = [];
        $client = $this->client($requests, [new MockResponse('<html>Bad gateway</html>', ['http_code' => 502])]);

        $this->expectExceptionMessage('Backblaze B2 contesta 502 en b2_authorize_account: error');
        $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
    }

    /**
     * El cliente HTTP mete la URL de subida en sus errores. La excepción que sale
     * de aquí no la lleva ni la encadena.
     */
    public function testUnCorteDeConexionNoDejaRastrosEnLaExcepcion(): void
    {
        $client = new B2Client(new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new TransportException(sprintf('Idle timeout reached for "%s".', $url));
        }), self::KEY_ID, self::APP_KEY);

        try {
            $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
            $this->fail('Debía fallar.');
        } catch (B2Exception $e) {
            $this->assertSame('No hay conexión con Backblaze B2 (b2_authorize_account).', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testSinClaveLaCopiaEstaApagadaYNoLlamaANadie(): void
    {
        $requests = [];
        $client = $this->client($requests, [], '', self::APP_KEY);

        $this->assertFalse($client->isConfigured());
        $this->expectException(B2Exception::class);
        $client->uploadLocked('x', 'x', new \DateTimeImmutable('+1 day'));
    }

    /**
     * Cliente con respuestas en orden, que apunta cada petición en `$requests`.
     *
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $requests
     * @param list<MockResponse>                                                   $responses
     */
    private function client(array &$requests, array $responses, string $keyId = self::KEY_ID, string $appKey = self::APP_KEY): B2Client
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? throw new \LogicException('Petición de más: ' . $url);
        });

        return new B2Client($http, $keyId, $appKey);
    }

    /**
     * Respuesta de b2_authorize_account (v4) para una clave con esos permisos.
     *
     * @param list<string>                                $capabilities
     * @param list<array{id: string, name: string}>|null $buckets
     */
    private function authorized(array $capabilities, ?array $buckets = null): MockResponse
    {
        return new MockResponse(json_encode([
            'accountId' => '000000000000',
            'authorizationToken' => 'account-token',
            'apiInfo' => ['storageApi' => [
                'apiUrl' => self::API_URL,
                'allowed' => [
                    'buckets' => $buckets ?? [['id' => 'bucket1', 'name' => 'bucket-de-copias']],
                    'capabilities' => $capabilities,
                    'namePrefix' => null,
                ],
            ]],
        ]));
    }

    /** Respuesta de b2_get_upload_url. */
    private function uploadUrl(): MockResponse
    {
        return new MockResponse(json_encode(['uploadUrl' => self::UPLOAD_URL, 'authorizationToken' => 'upload-token']));
    }

    /** Respuesta de b2_upload_file para un fichero. */
    private function uploaded(string $fileName): MockResponse
    {
        return new MockResponse(json_encode(['fileId' => '4_z' . $fileName, 'fileName' => $fileName]));
    }

    /**
     * Una cabecera de una petición apuntada, tal como la manda el cliente
     * ("Nombre: valor"), o null si no va.
     *
     * @param array{options: array<string, mixed>} $request
     */
    private function header(array $request, string $name): ?string
    {
        foreach ($request['options']['headers'] ?? [] as $line) {
            if (str_starts_with(strtolower($line), $name . ':')) {
                return $line;
            }
        }

        return null;
    }
}
