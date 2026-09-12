<?php

namespace App\Tests\Service\News;

use App\Service\News\NewsCatalog;
use App\Service\News\NewsEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * El fichero REAL de novedades del repositorio, no uno de mentira.
 *
 * EXISTE PARA CAZAR EL ÚNICO FALLO QUE NO SE VE. Las novedades se escriben a
 * mano en `data/novedades.yaml`, y el error fácil es darle a una entrada nueva
 * un número que ya está usado o que va hacia atrás. Eso no rompe nada: el
 * fichero se lee bien, la entrada aparece en la web… y no se le cuenta a nadie
 * nunca, porque el puntero de lo ya anunciado pasó por encima. Sin esta
 * comprobación, la novedad se pierde en silencio y sólo se descubre cuando
 * alguien pregunta por qué no le llegó.
 *
 * Aquí sólo se comprueba lo que es verdad SIEMPRE, en cualquier momento de la
 * vida del fichero: que se puede leer, y que los números crecen. Nada sobre el
 * contenido concreto, que cambia en cada release.
 */
class NewsFileTest extends TestCase
{
    public function testElFicheroDelRepositorioSeLeeSinErrores(): void
    {
        // Si esto revienta, la pantalla de novedades da error en producción: el
        // catálogo prefiere fallar a servir una lista "casi bien".
        $entries = $this->catalog()->all();

        self::assertContainsOnlyInstancesOf(NewsEntry::class, $entries);
    }

    /**
     * Se lee el YAML CRUDO y no el catálogo, que es el matiz que hace útil a
     * este test: el catálogo reordena por id al cargar, así que con sus datos
     * la lista siempre saldría creciente y la comprobación no diría nada. El
     * fichero conserva el orden en que se fueron añadiendo las entradas, y es
     * ahí donde se ve que la última lleva el número más alto.
     */
    public function testLasNovedadesEstanEscritasConNumeroCreciente(): void
    {
        $rows = Yaml::parseFile($this->file()) ?? [];

        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);

        $previous = 0;
        foreach ($ids as $position => $id) {
            self::assertGreaterThan(
                $previous,
                $id,
                sprintf(
                    'La novedad de la posición %d lleva el número %d, que no es mayor que el %d de la anterior. Una novedad con un número ya usado no se le cuenta a nadie: sube el número.',
                    $position + 1,
                    $id,
                    $previous
                )
            );
            $previous = $id;
        }
    }

    private function catalog(): NewsCatalog
    {
        return new NewsCatalog($this->file());
    }

    private function file(): string
    {
        return \dirname(__DIR__, 3) . '/data/novedades.yaml';
    }
}
