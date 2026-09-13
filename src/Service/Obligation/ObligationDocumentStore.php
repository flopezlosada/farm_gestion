<?php

namespace App\Service\Obligation;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\File;

/**
 * El archivo de los documentos de vencimientos: dónde se guarda el papel de
 * cada convenio, póliza o inscripción, y con qué nombre.
 *
 * FUERA DE `public/`, Y ESO ES EL PUNTO. Lo que cuelga del docroot lo sirve
 * Apache por su cuenta: sin pasar por Symfony, sin sesión y sin comprobar
 * ningún permiso. Estos documentos llevan DNIs, IBAN, firmas escaneadas y
 * referencias catastrales, así que quien tenga la URL no puede ser quien pueda
 * leerlos. Viven en `var/documentos/vencimientos/` y salen sólo por
 * {@see \App\Controller\ObligationController::document()}, que exige el permiso
 * de la sección.
 *
 * El nombre guardado NO es el original: se le pone un sufijo aleatorio. Así dos
 * personas pueden subir su «escaneado.pdf» sin pisarse, y de paso el nombre deja
 * de ser adivinable — aunque la puerta la cierra el permiso, no el nombre.
 */
final class ObligationDocumentStore
{
    /**
     * Tamaño máximo por documento. Conservador a propósito: el hosting recorta
     * las subidas por su cuenta (`upload_max_filesize`) y un fichero rechazado
     * por PHP llega al formulario como si no se hubiera enviado nada, que es la
     * peor forma de fallar. Un convenio escaneado normal no pasa de 3-4 MB.
     */
    public const MAX_SIZE = '12M';

    /**
     * Lo que se acepta subir. Se valida el contenido real del fichero, no su
     * extensión. SVG queda fuera aposta: es XML con JavaScript dentro y lo
     * servimos desde nuestro propio dominio.
     */
    public const MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
        'image/tiff',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
    ];

    public function __construct(
        #[Autowire('%app.obligation_documents_dir%')]
        private readonly string $directory,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Lo que se acepta archivar, como regla de validación.
     *
     * Vive aquí y no en el formulario porque el documento entra por dos
     * puertas: el formulario (alta, edición, renovación) y la subida suelta del
     * historial, que no tiene formulario. Con la regla escrita en cada puerta,
     * una de las dos acabaría aceptando lo que la otra rechaza.
     *
     * @return File Restricción de tamaño y formato.
     */
    public static function constraint(): File
    {
        return new File(
            maxSize: self::MAX_SIZE,
            mimeTypes: self::MIME_TYPES,
            maxSizeMessage: 'El documento pesa demasiado ({{ size }} {{ suffix }}). El máximo son {{ limit }} {{ suffix }}: si es un escaneado, vuelve a guardarlo con menos calidad.',
            mimeTypesMessage: 'Ese formato no se puede guardar. Vale un PDF, una foto o un escaneado (JPG, PNG, HEIC, TIFF) o un documento de texto (DOC, DOCX, ODT).',
        );
    }

    /**
     * Engancha un documento recién subido a la ficha o al periodo, y tira el
     * que hubiera antes.
     *
     * Que no venga fichero es el caso normal —se edita la ficha sin tocar el
     * papel—, así que no hacer nada es la respuesta correcta y no un error.
     *
     * @param UploadedFile|null           $upload Fichero del formulario, si lo hubo.
     * @param Obligation|ObligationTerm   $target Ficha o periodo al que se engancha.
     */
    public function attach(?UploadedFile $upload, Obligation|ObligationTerm $target): void
    {
        if (!$upload instanceof UploadedFile) {
            return;
        }

        $previous = $target->getDocumentFile();
        $target->setDocumentFile($this->store($upload));
        $this->discard($previous);
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
        $original = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = $this->slugger->slug($original)->lower()->truncate(80)->toString();
        $extension = $this->extensionOf($upload);

        $name = sprintf(
            '%s-%s%s',
            $slug !== '' ? $slug : 'documento',
            bin2hex(random_bytes(4)),
            $extension !== null ? '.' . $extension : ''
        );

        $upload->move($this->directory, $name);

        return $name;
    }

    /**
     * Ruta absoluta del documento, o null si el nombre no corresponde a ningún
     * fichero del archivo.
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
     * Borra un documento del archivo. Silencioso si ya no está: el objetivo es
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
     * @param UploadedFile $upload Fichero subido.
     *
     * @return string|null Extensión sin punto, o null si no hay ninguna fiable.
     */
    private function extensionOf(UploadedFile $upload): ?string
    {
        $guessed = $upload->guessExtension();

        if ($guessed !== null && $guessed !== '') {
            return $guessed;
        }

        $claimed = strtolower($upload->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,8}$/', $claimed) === 1 ? $claimed : null;
    }
}
