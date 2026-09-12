<?php

namespace App\Tests\Service\News;

use App\Service\News\NewsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * El lector del fichero de novedades.
 *
 * LO QUE DE VERDAD PROTEGE ES EL `id`. No es un adorno: es lo único que decide
 * qué se ha contado ya y qué no, así que un id repetido o que no es entero no
 * puede pasar en silencio — pasaría como un correo duplicado a toda la
 * asociación, o como una novedad que no sale nunca. Por eso el catálogo revienta
 * en vez de saltarse la entrada rota, y por eso la mitad de estos casos son
 * ficheros mal escritos.
 */
class NewsCatalogTest extends TestCase
{
    /** @var list<string> Ficheros temporales a borrar al terminar. */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    public function testLasDevuelveDeLaMasRecienteALaMasAntigua(): void
    {
        $catalog = $this->catalog(<<<'YAML'
            - id: 1
              fecha: '2026-01-15'
              titulo: 'La primera'
              texto: 'Lo que se hizo primero.'
            - id: 3
              fecha: '2026-03-01'
              titulo: 'La tercera'
              texto: 'Lo último.'
            - id: 2
              fecha: '2026-02-01'
              titulo: 'La segunda'
              texto: 'Lo de en medio.'
            YAML);

        // Ordenadas por id y no por el orden del fichero: quien añade una
        // entrada la pega donde le pilla, y la página tiene que salir bien igual.
        self::assertSame([3, 2, 1], array_map(static fn ($e) => $e->id, $catalog->all()));
        self::assertSame('La tercera', $catalog->all()[0]->title);
        self::assertSame(3, $catalog->latestId());
    }

    public function testLoPendienteEsLoQueEstaPorEncimaDelPuntero(): void
    {
        $catalog = $this->catalog($this->tresEntradas());

        self::assertSame([3, 2], array_map(static fn ($e) => $e->id, $catalog->since(1)));
        self::assertSame([], $catalog->since(3));
        // Sin haber anunciado nada, todo está pendiente.
        self::assertCount(3, $catalog->since(0));
    }

    public function testLoPublicadoEsLoQueEstaPorDebajoDelPuntero(): void
    {
        $catalog = $this->catalog($this->tresEntradas());

        self::assertSame([2, 1], array_map(static fn ($e) => $e->id, $catalog->through(2)));
        // Nada anunciado, nada que enseñar: lo pendiente NO se publica, o el
        // botón de administración dejaría de decidir cuándo se cuenta.
        self::assertSame([], $catalog->through(0));
    }

    public function testUnFicheroVacioNoRompeNada(): void
    {
        $catalog = $this->catalog('');

        self::assertSame([], $catalog->all());
        self::assertSame(0, $catalog->latestId());
        self::assertSame([], $catalog->since(0));
    }

    public function testUnIdRepetidoRevienta(): void
    {
        $catalog = $this->catalog(<<<'YAML'
            - id: 1
              fecha: '2026-01-15'
              titulo: 'Una'
              texto: 'Texto.'
            - id: 1
              fecha: '2026-02-15'
              titulo: 'Otra con el mismo id'
              texto: 'Texto.'
            YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('repetida');

        $catalog->all();
    }

    /**
     * @dataProvider ficherosRotos
     */
    public function testUnaEntradaMalEscritaRevienta(string $yaml): void
    {
        $this->expectException(\RuntimeException::class);

        $this->catalog($yaml)->all();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ficherosRotos(): iterable
    {
        yield 'sin título' => ["- id: 1\n  fecha: '2026-01-15'\n  texto: 'Texto.'\n"];
        yield 'sin texto' => ["- id: 1\n  fecha: '2026-01-15'\n  titulo: 'Una'\n"];
        yield 'sin fecha' => ["- id: 1\n  titulo: 'Una'\n  texto: 'Texto.'\n"];
        yield 'sin id' => ["- fecha: '2026-01-15'\n  titulo: 'Una'\n  texto: 'Texto.'\n"];
        yield 'título vacío' => ["- id: 1\n  fecha: '2026-01-15'\n  titulo: ''\n  texto: 'Texto.'\n"];
        // Un id de texto se ordenaría y compararía como texto: "10" iría antes
        // que "9" y el puntero dejaría de significar nada.
        yield 'id que no es entero' => ["- id: 'uno'\n  fecha: '2026-01-15'\n  titulo: 'Una'\n  texto: 'Texto.'\n"];
        yield 'id cero' => ["- id: 0\n  fecha: '2026-01-15'\n  titulo: 'Una'\n  texto: 'Texto.'\n"];
        yield 'fecha en otro formato' => ["- id: 1\n  fecha: '15/01/2026'\n  titulo: 'Una'\n  texto: 'Texto.'\n"];
    }

    public function testUnFicheroQueNoExisteRevienta(): void
    {
        $this->expectException(\RuntimeException::class);

        (new NewsCatalog('/no/existe/novedades.yaml'))->all();
    }

    /**
     * Un catálogo sobre un fichero temporal con el contenido dado.
     */
    private function catalog(string $yaml): NewsCatalog
    {
        $file = sys_get_temp_dir() . '/novedades-' . uniqid() . '.yaml';
        file_put_contents($file, $yaml);
        $this->files[] = $file;

        return new NewsCatalog($file);
    }

    private function tresEntradas(): string
    {
        return <<<'YAML'
            - id: 1
              fecha: '2026-01-15'
              titulo: 'La primera'
              texto: 'Texto.'
            - id: 2
              fecha: '2026-02-01'
              titulo: 'La segunda'
              texto: 'Texto.'
            - id: 3
              fecha: '2026-03-01'
              titulo: 'La tercera'
              texto: 'Texto.'
            YAML;
    }
}
