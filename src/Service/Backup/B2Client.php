<?php

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lo que la aplicación necesita de Backblaze B2: subir un fichero bloqueado
 * contra el borrado hasta una fecha.
 *
 * POR QUÉ LA API NATIVA Y NO LA S3: son tres llamadas con el cliente HTTP de
 * Symfony, y el SDK de AWS pesa decenas de megas que habría que subir por FTP.
 *
 * LA CLAVE DEL SERVIDOR NO PUEDE BORRAR. Es la mitad del diseño: si alguien entra
 * en el hosting, las copias que ya están fuera tienen que sobrevivirle. Por eso
 * {@see self::authorize()} rechaza una clave que no esté restringida a un único
 * bucket o que pueda borrar o saltarse el bloqueo, antes de subir nada: una
 * clave así seguiría funcionando y nadie notaría que la copia ya no protege.
 */
class B2Client
{
    private const AUTHORIZE_URL = 'https://api.backblazeb2.com/b2api/v4/b2_authorize_account';

    /** Permisos que la clave del servidor NO debe tener. */
    private const FORBIDDEN_CAPABILITIES = ['deleteFiles', 'bypassGovernance', 'writeKeys', 'deleteKeys'];

    /**
     * @param HttpClientInterface $http           Cliente HTTP.
     * @param string              $keyId          keyID de la clave del servidor (vacío = apagado).
     * @param string              $applicationKey Parte secreta de esa clave.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(B2_KEY_ID)%')]
        private readonly string $keyId,
        #[Autowire('%env(B2_APPLICATION_KEY)%')]
        private readonly string $applicationKey,
    ) {
    }

    /** Si hay clave con la que entrar. */
    public function isConfigured(): bool
    {
        return trim($this->keyId) !== '' && trim($this->applicationKey) !== '';
    }

    /**
     * Sube un fichero bloqueado en modo governance hasta `$retainUntil`.
     *
     * Governance y no compliance: con compliance no lo borra nadie, ni la junta,
     * hasta que venza. Governance sólo lo deja borrar a una clave con
     * `bypassGovernance`, que no es la del servidor.
     *
     * @param string             $fileName    Nombre dentro del bucket (puede llevar "/").
     * @param string             $content     Contenido.
     * @param \DateTimeImmutable $retainUntil Hasta cuándo no se puede borrar.
     *
     * @return array{fileId: string, fileName: string, bucket: string} Lo que B2 dice haber guardado.
     *
     * @throws B2Exception Si no se ha subido; dice por qué.
     */
    public function uploadLocked(string $fileName, string $content, \DateTimeImmutable $retainUntil): array
    {
        $session = $this->authorize();

        $target = $this->call('GET', $session['apiUrl'] . '/b2api/v4/b2_get_upload_url', [
            'headers' => ['Authorization' => $session['token']],
            'query' => ['bucketId' => $session['bucketId']],
        ]);

        $uploaded = $this->call('POST', (string) ($target['uploadUrl'] ?? ''), [
            'headers' => [
                'Authorization' => (string) ($target['authorizationToken'] ?? ''),
                'X-Bz-File-Name' => str_replace('%2F', '/', rawurlencode($fileName)),
                'Content-Type' => 'application/octet-stream',
                'X-Bz-Content-Sha1' => sha1($content),
                'X-Bz-File-Retention-Mode' => 'governance',
                'X-Bz-File-Retention-Retain-Until-Timestamp' => (string) ($retainUntil->getTimestamp() * 1000),
            ],
            'body' => $content,
            'timeout' => 120,
        ]);

        return [
            'fileId' => (string) ($uploaded['fileId'] ?? ''),
            'fileName' => (string) ($uploaded['fileName'] ?? $fileName),
            'bucket' => $session['bucketName'],
        ];
    }

    /**
     * Entra en B2 y comprueba que la clave es la que debe ser.
     *
     * @return array{apiUrl: string, token: string, bucketId: string, bucketName: string}
     *
     * @throws B2Exception Si la clave no vale o puede más de lo que debe.
     */
    private function authorize(): array
    {
        if (!$this->isConfigured()) {
            throw new B2Exception('La copia fuera del hosting no está configurada (faltan B2_KEY_ID y B2_APPLICATION_KEY).');
        }

        $account = $this->call('GET', self::AUTHORIZE_URL, [
            'auth_basic' => [trim($this->keyId), trim($this->applicationKey)],
        ]);
        $storage = $account['apiInfo']['storageApi'] ?? [];
        $buckets = $storage['allowed']['buckets'] ?? [];
        $capabilities = $storage['allowed']['capabilities'] ?? [];

        if (\count($buckets) !== 1) {
            throw new B2Exception('La clave de B2 tiene que estar restringida a un único bucket.');
        }
        $excess = array_values(array_intersect(self::FORBIDDEN_CAPABILITIES, $capabilities));
        if ($excess !== []) {
            throw new B2Exception(sprintf('La clave de B2 puede más de lo que debe (%s): créala sin esos permisos.', implode(', ', $excess)));
        }

        return [
            'apiUrl' => (string) ($storage['apiUrl'] ?? ''),
            'token' => (string) ($account['authorizationToken'] ?? ''),
            'bucketId' => (string) ($buckets[0]['id'] ?? ''),
            'bucketName' => (string) ($buckets[0]['name'] ?? ''),
        ];
    }

    /**
     * Una llamada a la API que devuelve JSON.
     *
     * El error del cliente HTTP no se encadena: puede llevar cabeceras o la URL de
     * subida, y la excepción acaba en la salida de la tarea.
     *
     * @param string               $method  Método HTTP.
     * @param string               $url     URL completa.
     * @param array<string, mixed> $options Opciones del cliente HTTP.
     *
     * @return array<string, mixed>
     *
     * @throws B2Exception Si no hay conexión o B2 contesta con error.
     */
    private function call(string $method, string $url, array $options): array
    {
        $endpoint = basename((string) parse_url($url, \PHP_URL_PATH));

        try {
            $response = $this->http->request($method, $url, $options + ['timeout' => 30]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw new B2Exception(sprintf('No hay conexión con Backblaze B2 (%s).', $endpoint));
        }

        try {
            $payload = $response->toArray(false);
        } catch (ExceptionInterface) {
            // Un error de un proxy o de un balanceador puede venir en HTML: lo que
            // importa entonces es el código, no el cuerpo.
            $payload = [];
        }

        if ($status >= 400) {
            throw new B2Exception(sprintf(
                'Backblaze B2 contesta %d en %s: %s%s',
                $status,
                $endpoint,
                (string) ($payload['code'] ?? 'error'),
                isset($payload['message']) ? ' — ' . $payload['message'] : '',
            ));
        }
        if ($payload === []) {
            throw new B2Exception(sprintf('Backblaze B2 ha devuelto algo que no se entiende en %s.', $endpoint));
        }

        return $payload;
    }
}
