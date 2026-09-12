<?php

namespace App\Service\News;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Lee las novedades del fichero del repo y las valida. La única puerta de
 * entrada al contenido.
 *
 * REVIENTA SI EL FICHERO ESTÁ MAL, en vez de saltarse la entrada rota. Un id
 * repetido o que va hacia atrás no es un adorno: es lo que decide qué se ha
 * anunciado y qué no, así que una lista "casi bien" mandaría avisos duplicados
 * o dejaría novedades invisibles para siempre. Mejor un error en la pantalla de
 * novedades —que sólo la ve quien puede arreglarlo— que un fallo silencioso en
 * el correo de doscientas personas. Por eso NO se consulta desde el menú ni
 * desde el layout: ahí una excepción tumbaría toda la web.
 *
 * SE LEE EN CADA PETICIÓN QUE LO PIDA y no se cachea. Son unas pocas decenas de
 * entradas en un YAML de kilobytes, y el cache traería el problema de siempre
 * del hosting: hay que acordarse de vaciarlo tras el despliegue. El fichero se
 * memoiza dentro de la misma petición, que es lo único que hacía falta.
 */
class NewsCatalog
{
    /** @var list<NewsEntry>|null Memo de la petición. */
    private ?array $entries = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/data/novedades.yaml')]
        private readonly string $file,
    ) {
    }

    /**
     * Todas las novedades, de la más reciente a la más antigua.
     *
     * @return list<NewsEntry> ordenadas por id descendente
     */
    public function all(): array
    {
        return $this->entries ??= $this->load();
    }

    /**
     * Las que tienen un id mayor que el dado, de la más reciente a la más
     * antigua. Es lo que contesta "¿qué queda por anunciar?".
     *
     * @param int $afterId último id ya anunciado; 0 si no se ha anunciado nada
     *
     * @return list<NewsEntry>
     */
    public function since(int $afterId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NewsEntry $entry): bool => $entry->id > $afterId
        ));
    }

    /**
     * Las que ya se anunciaron, que son las que se le pueden enseñar a lxs
     * socixs.
     *
     * Las pendientes NO se publican en la web, y no es un detalle: si la página
     * enseñara lo que aún no se ha anunciado, el botón de administración dejaría
     * de decidir nada — la novedad ya estaría contada a quien pasara por ahí.
     *
     * @param int $throughId último id anunciado; 0 si no se ha anunciado nada
     *
     * @return list<NewsEntry>
     */
    public function through(int $throughId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NewsEntry $entry): bool => $entry->id <= $throughId
        ));
    }

    /**
     * El id más alto del fichero, o 0 si está vacío. Es hasta dónde llegaría un
     * anuncio hecho ahora mismo.
     */
    public function latestId(): int
    {
        $all = $this->all();

        return [] === $all ? 0 : $all[0]->id;
    }

    /**
     * Parsea y valida el fichero.
     *
     * @return list<NewsEntry> ordenadas por id descendente
     *
     * @throws \RuntimeException si el fichero falta o alguna entrada está mal
     */
    private function load(): array
    {
        if (!is_readable($this->file)) {
            throw new \RuntimeException(sprintf('No se puede leer el fichero de novedades "%s".', $this->file));
        }

        $raw = Yaml::parseFile($this->file) ?? [];
        if (!is_array($raw)) {
            throw new \RuntimeException('El fichero de novedades debe ser una lista de entradas.');
        }

        $entries = [];
        $seen = [];
        foreach ($raw as $position => $row) {
            $entry = $this->parse($row, $position);

            if (isset($seen[$entry->id])) {
                throw new \RuntimeException(sprintf('Novedad con id %d repetida: el id decide qué se ha anunciado ya.', $entry->id));
            }
            $seen[$entry->id] = true;

            $entries[] = $entry;
        }

        usort($entries, static fn (NewsEntry $a, NewsEntry $b): int => $b->id <=> $a->id);

        return $entries;
    }

    /**
     * Una fila del YAML a {@see NewsEntry}.
     *
     * @param mixed $row      lo que venga en esa posición
     * @param int   $position posición en el fichero, para que el error sea útil
     */
    private function parse(mixed $row, int $position): NewsEntry
    {
        if (!is_array($row)) {
            throw new \RuntimeException(sprintf('La novedad de la posición %d no es un bloque de campos.', $position + 1));
        }

        foreach (['id', 'fecha', 'titulo', 'texto'] as $field) {
            if (!isset($row[$field]) || '' === trim((string) $row[$field])) {
                throw new \RuntimeException(sprintf('A la novedad de la posición %d le falta "%s".', $position + 1, $field));
            }
        }

        if (!is_int($row['id']) || $row['id'] < 1) {
            throw new \RuntimeException(sprintf('El id de la novedad de la posición %d debe ser un entero positivo.', $position + 1));
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['fecha']);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \RuntimeException(sprintf('La fecha de la novedad %d no tiene el formato AAAA-MM-DD.', $row['id']));
        }

        return new NewsEntry(
            $row['id'],
            $date,
            trim((string) $row['titulo']),
            trim((string) $row['texto']),
        );
    }
}
