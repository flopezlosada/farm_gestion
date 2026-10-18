<?php

namespace App\Tests\Service\Backup;

use App\Service\Backup\B2Client;
use App\Service\Backup\DatabaseDumper;
use App\Service\Backup\OffsiteBackup;
use App\Service\Backup\SealedBox;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Qué sale del servidor, con qué nombre y cuánto queda bloqueado: sólo los
 * documentos que no están fuera o han cambiado, y todo cifrado para la privada.
 */
class OffsiteBackupTest extends TestCase
{
    private const DEFAULT_URI = 'https://csavegadejarama.org';

    private const PREFIX = 'csavegadejarama.org/documentos/';

    private string $documentsDir;

    private string $publicKey;

    private string $secretKey;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<array{fileName: string, fileInfo: array<string, string>}> Lo que B2 dice tener. */
    private array $remote = [];

    protected function setUp(): void
    {
        $this->documentsDir = sys_get_temp_dir() . '/offsite-backup-' . bin2hex(random_bytes(4));
        $keyPair = sodium_crypto_box_keypair();
        $this->publicKey = base64_encode(sodium_crypto_box_publickey($keyPair));
        $this->secretKey = base64_encode(sodium_crypto_box_secretkey($keyPair));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->documentsDir);
    }

    /**
     * Una factura ya subida y sin cambios no se vuelve a subir; una nueva y una
     * regenerada (mismo nombre, otro contenido) sí.
     */
    public function testSoloQuedaPendienteLoNuevoYLoQueHaCambiado(): void
    {
        $this->document('facturas/22.pdf', 'factura 22');
        $this->document('facturas-emitidas/7.pdf', 'emitida 7, versión nueva');
        $this->document('vencimientos/convenio.pdf', 'convenio');
        $this->remote = [
            ['fileName' => self::PREFIX . 'facturas/22.pdf.sealed', 'fileInfo' => ['plain_sha1' => sha1('factura 22')]],
            ['fileName' => self::PREFIX . 'facturas-emitidas/7.pdf.sealed', 'fileInfo' => ['plain_sha1' => sha1('emitida 7')]],
        ];

        $pending = $this->backup()->pendingDocuments();

        $this->assertSame([
            'facturas-emitidas/7.pdf' => sha1('emitida 7, versión nueva'),
            'vencimientos/convenio.pdf' => sha1('convenio'),
        ], $pending);
        $this->assertStringContainsString('prefix=' . self::PREFIX, $this->requests[1]['url']);
    }

    /** Sin documentos en disco no se pregunta nada a B2. */
    public function testSinDocumentosNoLlamaAB2(): void
    {
        $this->assertSame([], $this->backup()->pendingDocuments());
        $this->assertSame([], $this->requests);
    }

    /**
     * El documento sale cifrado, bajo la carpeta del dominio, bloqueado seis años
     * y con el SHA-1 en claro que permite saber después si ha cambiado.
     */
    public function testElDocumentoSaleCifradoYBloqueadoSeisAnos(): void
    {
        $this->document('facturas/22.pdf', 'factura 22');
        $now = new \DateTimeImmutable('2026-10-19 03:00:00');

        $backup = $this->backup();

        $result = $backup->backUpDocuments($backup->pendingDocuments(), $now, microtime(true) + 60);

        $upload = $this->requests[3];
        $this->assertSame(['uploaded' => 1, 'bytes' => 10, 'left' => 0, 'unreadable' => []], $result);
        $this->assertSame('X-Bz-File-Name: ' . self::PREFIX . 'facturas/22.pdf.sealed', $this->header($upload, 'x-bz-file-name'));
        $this->assertSame('X-Bz-Info-plain_sha1: ' . sha1('factura 22'), $this->header($upload, 'x-bz-info-plain_sha1'));
        $this->assertSame((string) ($now->modify('+6 years')->getTimestamp() * 1000), $this->headerValue($upload, 'x-bz-file-retention-retain-until-timestamp'));
        $this->assertSame('factura 22', SealedBox::open($this->body($upload), $this->secretKey));
    }

    /**
     * Un documento que ya no está (o no se puede leer) se apunta y no frena a los
     * que van detrás: si cortara, nunca se subiría nada posterior en el orden.
     */
    public function testUnDocumentoIlegibleNoFrenaALosDemas(): void
    {
        $this->document('facturas/30.pdf', 'factura 30');
        $pending = ['facturas/29.pdf' => sha1('borrada'), 'facturas/30.pdf' => sha1('factura 30')];

        $result = $this->backup()->backUpDocuments($pending, new \DateTimeImmutable(), microtime(true) + 60);

        $this->assertSame(['uploaded' => 1, 'bytes' => 10, 'left' => 0, 'unreadable' => ['facturas/29.pdf']], $result);
    }

    /** Agotado el tiempo no se empieza ninguno más; quedan para la siguiente copia. */
    public function testConElTiempoAgotadoNoSubeNadaYDiceCuantosQuedan(): void
    {
        $this->document('facturas/22.pdf', 'factura 22');

        $result = $this->backup()->backUpDocuments(['facturas/22.pdf' => sha1('factura 22')], new \DateTimeImmutable(), microtime(true) - 1);

        $this->assertSame(['uploaded' => 0, 'bytes' => 0, 'left' => 1, 'unreadable' => []], $result);
        $this->assertSame([], $this->requests);
    }

    /** Los ficheros ocultos (.gitkeep) no son documentos. */
    public function testLosFicherosOcultosNoSeCopian(): void
    {
        $this->document('facturas/.gitkeep', '');
        $this->document('facturas/22.pdf', 'factura 22');

        $this->assertSame(['facturas/22.pdf'], array_keys($this->backup()->pendingDocuments()));
    }

    /** El volcado de la base lleva la fecha en el nombre y caduca a los noventa días. */
    public function testLaBaseSaleCifradaYBloqueadaNoventaDias(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 03:00:00');

        $database = $this->backup('volcado-gzip')->backUpDatabase($now);

        $upload = $this->requests[2];
        $this->assertSame(['name' => 'csavegadejarama.org/base/20261019-030000.sql.gz.sealed', 'bytes' => 12], $database);
        $this->assertSame('X-Bz-File-Name: ' . $database['name'], $this->header($upload, 'x-bz-file-name'));
        $this->assertSame((string) ($now->modify('+90 days')->getTimestamp() * 1000), $this->headerValue($upload, 'x-bz-file-retention-retain-until-timestamp'));
        $this->assertSame('volcado-gzip', SealedBox::open($this->body($upload), $this->secretKey));
    }

    /**
     * Sin dominio no hay carpeta: mejor fallar que mezclar dos instalaciones. Y
     * fallar antes de volcar, que es lo caro.
     */
    public function testSinDominioEnDefaultUriNoVuelcaNiSube(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no tiene dominio');

        $this->backup(defaultUri: '', dumpCalls: $this->never())->backUpDatabase(new \DateTimeImmutable());
    }

    /**
     * El servicio con un B2 simulado que contesta según el endpoint y apunta cada
     * petición.
     */
    private function backup(string $dump = '', string $defaultUri = self::DEFAULT_URI, ?InvocationOrder $dumpCalls = null): OffsiteBackup
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return match (true) {
                str_contains($url, 'b2_authorize_account') => new MockResponse(json_encode([
                    'authorizationToken' => 'account-token',
                    'apiInfo' => ['storageApi' => [
                        'apiUrl' => 'https://api003.backblazeb2.com',
                        'allowed' => ['buckets' => [['id' => 'bucket1', 'name' => 'copias']], 'capabilities' => ['listFiles', 'writeFiles']],
                    ]],
                ])),
                str_contains($url, 'b2_list_file_names') => new MockResponse(json_encode(['files' => $this->remote, 'nextFileName' => null])),
                str_contains($url, 'b2_get_upload_url') => new MockResponse(json_encode(['uploadUrl' => 'https://pod.backblaze.com/upload', 'authorizationToken' => 'upload-token'])),
                default => new MockResponse(json_encode(['fileId' => '4_z1', 'fileName' => 'x'])),
            };
        });
        $dumper = $this->createMock(DatabaseDumper::class);
        $dumper->expects($dumpCalls ?? $this->any())->method('dump')->willReturn($dump);

        return new OffsiteBackup(new B2Client($http, 'key-id', 'app-key'), new SealedBox($this->publicKey), $dumper, $this->documentsDir, $defaultUri);
    }

    private function document(string $path, string $content): void
    {
        (new Filesystem())->dumpFile($this->documentsDir . '/' . $path, $content);
    }

    /**
     * Una cabecera de una petición apuntada ("Nombre: valor"), o null si no va.
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

    /**
     * Sólo el valor de una cabecera.
     *
     * @param array{options: array<string, mixed>} $request
     */
    private function headerValue(array $request, string $name): ?string
    {
        $line = $this->header($request, $name);

        return $line === null ? null : trim(explode(':', $line, 2)[1]);
    }

    /**
     * El cuerpo que se mandó.
     *
     * @param array{options: array<string, mixed>} $request
     */
    private function body(array $request): string
    {
        return (string) $request['options']['body'];
    }
}
