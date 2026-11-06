<?php

namespace App\Tests\Entity;

use App\Entity\Image;
use App\Entity\RemovesUploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de {@see RemovesUploadedFile}: borrar una entidad con fichero subido
 * borra el fichero si está, y no revienta si ya no está (subida perdida o copia
 * local sin `uploads/`), porque el callback corre dentro del flush y un error
 * dejaría la fila sin borrar.
 */
class RemovesUploadedFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/removes-uploaded-file-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testBorraElFicheroSiExiste(): void
    {
        $path = $this->dir . '/6abbc9cbc9d5d6.69790027.jpg';
        file_put_contents($path, 'jpeg');

        $this->entityPointingTo($path)->removeUpload();

        $this->assertFileDoesNotExist($path);
    }

    public function testFicheroQueYaNoExisteNoLanza(): void
    {
        $path = $this->dir . '/6abbc9cbc9d5d6.69790027.jpg';

        $this->entityPointingTo($path)->removeUpload();

        $this->assertFileDoesNotExist($path);
    }

    public function testSinRutaNoHaceNada(): void
    {
        $this->entityPointingTo(null)->removeUpload();

        $this->addToAssertionCount(1);
    }

    public function testRutaQueEsUnDirectorioNoLoBorra(): void
    {
        $this->entityPointingTo($this->dir)->removeUpload();

        $this->assertDirectoryExists($this->dir);
    }

    public function testImagenSinFicheroAsignadoNoLanzaAlBorrarse(): void
    {
        (new Image())->removeUpload();

        $this->addToAssertionCount(1);
    }

    /**
     * Entidad mínima con el mismo contrato que las reales: `removeUpload()` delega
     * en el trait con la ruta absoluta del fichero.
     */
    private function entityPointingTo(?string $path): object
    {
        return new class($path) {
            use RemovesUploadedFile;

            public function __construct(private ?string $path)
            {
            }

            public function removeUpload(): void
            {
                $this->deleteUploadedFile($this->path);
            }
        };
    }
}
