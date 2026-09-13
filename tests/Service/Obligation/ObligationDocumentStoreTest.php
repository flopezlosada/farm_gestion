<?php

namespace App\Tests\Service\Obligation;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use App\Service\Obligation\ObligationDocumentStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * El archivo de documentos de vencimientos.
 *
 * Lo que se protege aquí es que un documento no pise a otro (dos personas
 * subiendo su «escaneado.pdf» el mismo día es el caso normal, no el raro), que
 * sustituir uno no deje el anterior ocupando sitio para siempre, y que lo que
 * se sirve salga siempre de dentro del archivo aunque la columna de la base
 * diga otra cosa.
 */
class ObligationDocumentStoreTest extends TestCase
{
    private string $directory;

    private ObligationDocumentStore $store;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir() . '/obligation-docs-' . bin2hex(random_bytes(4));
        $this->store = new ObligationDocumentStore($this->directory, new AsciiSlugger(), $this->filesystem);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->directory);
    }

    /**
     * El nombre guardado conserva el original para que se reconozca, pero lleva
     * un sufijo propio: sin él, el segundo «escaneado.pdf» borraría el primero
     * sin avisar a nadie.
     */
    public function testDosDocumentosConElMismoNombreNoSePisan(): void
    {
        $first = $this->store->store($this->upload('Convenio La Cerrada.pdf'));
        $second = $this->store->store($this->upload('Convenio La Cerrada.pdf'));

        $this->assertMatchesRegularExpression('/^convenio-la-cerrada-[0-9a-f]{8}\.pdf$/', $first);
        $this->assertNotSame($first, $second);
        $this->assertFileExists($this->directory . '/' . $first);
        $this->assertFileExists($this->directory . '/' . $second);
    }

    /**
     * Sustituir el documento de una ficha tira el anterior. Guardarlos todos
     * llenaría el archivo de versiones que nadie sabría distinguir.
     */
    public function testSustituirElDocumentoBorraElQueHabia(): void
    {
        $obligation = new Obligation();

        $this->store->attach($this->upload('poliza-2025.pdf'), $obligation);
        $old = $obligation->getDocumentFile();

        $this->store->attach($this->upload('poliza-2026.pdf'), $obligation);
        $new = $obligation->getDocumentFile();

        $this->assertNotSame($old, $new);
        $this->assertFileDoesNotExist($this->directory . '/' . $old);
        $this->assertFileExists($this->directory . '/' . $new);
    }

    /**
     * Editar la ficha sin tocar el papel es lo normal: que no venga fichero no
     * puede significar «quítale el que tenía».
     */
    public function testSinFicheroNuevoElDocumentoSeQuedaComoEstaba(): void
    {
        $term = new ObligationTerm();
        $this->store->attach($this->upload('convenio.pdf'), $term);
        $stored = $term->getDocumentFile();

        $this->store->attach(null, $term);

        $this->assertSame($stored, $term->getDocumentFile());
        $this->assertFileExists($this->directory . '/' . $stored);
    }

    /**
     * El nombre sale de la base de datos, pero se trata como si llegara de
     * fuera: con una ruta relativa dentro, el controlador de descarga serviría
     * cualquier fichero del servidor.
     */
    public function testNoSirveNadaDeFueraDelArchivo(): void
    {
        $outside = $this->directory . '/../secreto-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'credenciales');

        try {
            $this->assertNull($this->store->pathTo('../' . basename($outside)));
        } finally {
            $this->filesystem->remove($outside);
        }
    }

    /**
     * Pasa de verdad: la base se restaura de un volcado y los ficheros no
     * viajan con ella. La fila apunta a un documento que ya no está y quien
     * llama necesita poder contestar un 404 en vez de reventar.
     */
    public function testDevuelveNullCuandoElFicheroYaNoEsta(): void
    {
        $this->assertNull($this->store->pathTo('convenio-fantasma-aabbccdd.pdf'));
        $this->assertNull($this->store->pathTo(null));
        $this->assertNull($this->store->pathTo(''));
    }

    /**
     * Un fichero subido de mentira, con contenido de PDF de verdad para que la
     * detección de tipo por contenido no dependa de la extensión.
     *
     * @param string $name Nombre con el que llega del navegador.
     */
    private function upload(string $name): UploadedFile
    {
        $path = sys_get_temp_dir() . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        // El último true marca el fichero como "de test": sin él, move() usa
        // move_uploaded_file() y falla fuera de una petición HTTP real.
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
