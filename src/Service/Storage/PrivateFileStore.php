<?php

namespace App\Service\Storage;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Un archivo de ficheros subidos que NO se sirve desde la web por su cuenta.
 *
 * FUERA DE `public/`, Y ESO ES EL PUNTO. Lo que cuelga del docroot lo sirve
 * Apache sin pasar por Symfony, sin sesión y sin comprobar ningún permiso, así
 * que quien tuviera la URL podría leerlo. Lo que se guarda aquí sale sólo por un
 * controlador que exige el permiso de su sección.
 *
 * El nombre guardado NO es el original: se le pone un sufijo aleatorio. Así dos
 * personas pueden subir su «escaneado.pdf» sin pisarse, y de paso el nombre deja
 * de ser adivinable — aunque la puerta la cierra el permiso, no el nombre.
 *
 * Cada archivo concreto (los papeles de vencimientos, las facturas recibidas…)
 * hereda de aquí para fijar su carpeta y sus reglas de formato. La mecánica de
 * guardar, localizar y borrar es la misma y vive una sola vez.
 */
abstract class PrivateFileStore
{
    /**
     * @param string           $directory  Carpeta del archivo, fuera del docroot.
     * @param SluggerInterface $slugger    Para limpiar el nombre original.
     * @param Filesystem       $filesystem Para borrar.
     */
    public function __construct(
        private readonly string $directory,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Guarda el fichero en el archivo y devuelve el nombre con el que quedó.
     *
     * @param UploadedFile $upload Fichero subido.
     *
     * @return string Nombre guardado, que es lo que se persiste en la fila.
     */
    public function store(UploadedFile $upload): string
    {
        return $this->storeFile($upload, $upload->getClientOriginalName());
    }

    /**
     * Guarda un fichero que no viene de un formulario (uno descargado de otro
     * servicio, por ejemplo) y devuelve el nombre con el que quedó. El fichero se
     * MUEVE: deja de estar donde estaba.
     *
     * @param File   $file         Fichero en disco.
     * @param string $originalName Nombre con que llegó, para el nombre guardado.
     *
     * @return string Nombre guardado, que es lo que se persiste en la fila.
     */
    public function storeFile(File $file, string $originalName): string
    {
        $original = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = $this->slugger->slug($original)->lower()->truncate(80)->toString();
        $extension = $this->extensionOf($file, $originalName);

        $name = sprintf(
            '%s-%s%s',
            $slug !== '' ? $slug : 'documento',
            bin2hex(random_bytes(4)),
            $extension !== null ? '.' . $extension : ''
        );

        $file->move($this->directory, $name);

        return $name;
    }

    /**
     * Ruta absoluta del fichero, o null si el nombre no corresponde a ninguno
     * del archivo.
     *
     * Devolver null en vez de la ruta cuando falta el fichero deja que quien
     * llama conteste un 404 honesto. Pasa de verdad: la base se restaura de un
     * volcado y los ficheros no viajan con ella.
     *
     * @param string|null $name Nombre guardado en la fila.
     *
     * @return string|null Ruta absoluta existente, o null.
     */
    public function pathTo(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        // El nombre sale de la base, pero se trata como si viniera de fuera: un
        // `../../.env` en esa columna serviría el fichero equivocado.
        $path = $this->directory . '/' . basename($name);

        return is_file($path) ? $path : null;
    }

    /**
     * Borra un fichero del archivo. Silencioso si ya no está: el objetivo es
     * que no quede, no que hubiera estado.
     *
     * @param string|null $name Nombre guardado en la fila.
     */
    public function discard(?string $name): void
    {
        $path = $this->pathTo($name);

        if ($path !== null) {
            $this->filesystem->remove($path);
        }
    }

    /**
     * Extensión con la que guardar, deducida del contenido y no de lo que diga
     * el nombre del cliente. Si el contenido no la delata, se acepta la del
     * nombre siempre que sea inofensiva.
     *
     * @param File   $file         Fichero en disco.
     * @param string $originalName Nombre con que llegó.
     *
     * @return string|null Extensión sin punto, o null si no hay ninguna fiable.
     */
    private function extensionOf(File $file, string $originalName): ?string
    {
        $guessed = $file->guessExtension();

        if ($guessed !== null && $guessed !== '') {
            return $guessed;
        }

        $claimed = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $claimed) === 1 ? $claimed : null;
    }
}
