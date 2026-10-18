<?php

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Qué se copia fuera del hosting, con qué nombre y cuánto tiempo queda bloqueado.
 *
 * Dos cosas, porque una sin la otra no sirve: los documentos de `var/documentos`
 * (facturas, convenios, pólizas) son ficheros sueltos como `22.pdf`, y lo que dice
 * de quién es cada uno, y el libro, sólo vive en la base.
 *
 * - **La base**: un volcado entero en cada copia, bloqueado
 *   {@see self::DATABASE_RETENTION}. Pesa menos de un mega comprimido; guardarlos
 *   años acumularía miles sin que ninguno viejo sirva para nada.
 * - **Los documentos**: sólo los que no están ya fuera o han cambiado, bloqueados
 *   {@see self::DOCUMENT_RETENTION}, lo que hay que guardar las facturas. Lo que
 *   ya hay subido se pregunta a B2 en cada copia en vez de apuntarlo aquí: así
 *   no hay un estado local que se pierda o se desincronice al restaurar.
 *
 * Todo va cifrado ({@see SealedBox}) y bajo una carpeta con el nombre del
 * dominio de la instalación, para que dos instalaciones con el mismo bucket no
 * se pisen ni se den por subidos los ficheros de la otra.
 */
class OffsiteBackup
{
    /** Cuánto queda bloqueado cada volcado de la base. */
    private const DATABASE_RETENTION = '+90 days';

    /** Cuánto queda bloqueado cada documento: lo que obliga a guardar una factura. */
    private const DOCUMENT_RETENTION = '+6 years';

    /** Extensión de lo que sale cifrado; {@see \App\Command\OpenBackupCommand} la quita al abrirlo. */
    private const SEALED_SUFFIX = '.sealed';

    /** Dato propio de B2 con el SHA-1 del documento en claro (el cifrado cambia en cada subida). */
    private const PLAIN_SHA1_INFO = 'plain_sha1';

    /**
     * @param B2Client       $b2           Destino.
     * @param SealedBox      $sealer       Cifrado.
     * @param DatabaseDumper $dumper       Volcado de la base.
     * @param string         $documentsDir Carpeta de los documentos.
     * @param string         $defaultUri   URL de la instalación; su dominio da la carpeta del bucket.
     */
    public function __construct(
        private readonly B2Client $b2,
        private readonly SealedBox $sealer,
        private readonly DatabaseDumper $dumper,
        #[Autowire('%kernel.project_dir%/var/documentos')]
        private readonly string $documentsDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    /** Si hay claves con las que subir y cifrar. */
    public function isConfigured(): bool
    {
        return $this->b2->isConfigured() && $this->sealer->isConfigured();
    }

    /**
     * Vuelca la base, la cifra y la sube.
     *
     * @param \DateTimeImmutable $now Momento de la copia; da el nombre y el plazo.
     *
     * @return array{name: string, bytes: int} Nombre en el bucket y tamaño comprimido en claro.
     *
     * @throws B2Exception Si no se ha subido.
     */
    public function backUpDatabase(\DateTimeImmutable $now): array
    {
        $name = sprintf('%s/base/%s.sql.gz%s', $this->folder(), $now->format('Ymd-His'), self::SEALED_SUFFIX);
        $dump = $this->dumper->dump();
        $this->b2->uploadLocked($name, $this->sealer->seal($dump), $now->modify(self::DATABASE_RETENTION));

        return ['name' => $name, 'bytes' => \strlen($dump)];
    }

    /**
     * Los documentos que no están fuera o han cambiado desde que se subieron.
     *
     * @return array<string, string> SHA-1 del contenido por ruta relativa a la carpeta de documentos.
     *
     * @throws B2Exception Si no se puede preguntar a B2 qué hay.
     */
    public function pendingDocuments(): array
    {
        $local = $this->localDocuments();
        if ($local === []) {
            return [];
        }
        $prefix = $this->documentPrefix();
        $remote = $this->b2->listFiles($prefix);

        return array_filter(
            $local,
            static fn (string $sha1, string $path): bool => ($remote[$prefix . $path . self::SEALED_SUFFIX][self::PLAIN_SHA1_INFO] ?? null) !== $sha1,
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Sube los documentos pendientes hasta que se acabe el tiempo.
     *
     * Pasado `$deadline` no se empieza ninguno más: lo que falte sale en la
     * siguiente copia. Un documento que no se puede leer se apunta y se sigue con
     * los demás, porque si cortara la copia, todos los que van detrás en el orden
     * se quedarían sin subir en cada ejecución. Un error de B2, en cambio, corta:
     * casi nunca es de un fichero, y seguir sólo acumularía esperas.
     *
     * @param array<string, string> $pending  Lo que da {@see self::pendingDocuments()}.
     * @param \DateTimeImmutable    $now      Momento de la copia; da el plazo.
     * @param float                 $deadline Instante (microtime) a partir del cual no se empieza otro.
     *
     * @return array{uploaded: int, bytes: int, left: int, unreadable: list<string>}
     *
     * @throws B2Exception Si B2 no acepta una subida.
     */
    public function backUpDocuments(array $pending, \DateTimeImmutable $now, float $deadline): array
    {
        $result = ['uploaded' => 0, 'bytes' => 0, 'left' => \count($pending), 'unreadable' => []];

        foreach (array_keys($pending) as $path) {
            if (microtime(true) >= $deadline) {
                break;
            }
            --$result['left'];
            $content = @file_get_contents($this->documentsDir . '/' . $path);
            if ($content === false) {
                $result['unreadable'][] = (string) $path;
                continue;
            }

            $this->b2->uploadLocked(
                $this->documentPrefix() . $path . self::SEALED_SUFFIX,
                $this->sealer->seal($content),
                $now->modify(self::DOCUMENT_RETENTION),
                [self::PLAIN_SHA1_INFO => sha1($content)],
            );
            ++$result['uploaded'];
            $result['bytes'] += \strlen($content);
        }

        return $result;
    }

    /**
     * Los documentos que hay en disco, sin los ocultos (`.gitkeep` y similares,
     * que no son documentos).
     *
     * @return array<string, string> SHA-1 del contenido por ruta relativa, con "/".
     */
    private function localDocuments(): array
    {
        if (!is_dir($this->documentsDir)) {
            return [];
        }

        $root = rtrim($this->documentsDir, '/') . '/';
        $files = [];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                // Uno ilegible queda pendiente con hash vacío y se apunta al subir.
                $files[substr($file->getPathname(), \strlen($root))] = (string) @sha1_file($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private function documentPrefix(): string
    {
        return $this->folder() . '/documentos/';
    }

    /**
     * Carpeta del bucket de esta instalación: el dominio de DEFAULT_URI.
     *
     * @throws \LogicException Si DEFAULT_URI no tiene dominio.
     */
    private function folder(): string
    {
        $host = parse_url($this->defaultUri, \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new \LogicException(sprintf('DEFAULT_URI (%s) no tiene dominio: no sé en qué carpeta del bucket copiar.', $this->defaultUri));
        }

        return $host;
    }
}
