<?php

namespace App\Service\Obligation;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use App\Service\Storage\PrivateFileStore;
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
 * La mecánica de guardar, localizar y borrar es la de cualquier archivo privado
 * ({@see PrivateFileStore}); aquí sólo se fijan la carpeta y lo que se acepta.
 */
final class ObligationDocumentStore extends PrivateFileStore
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

    /**
     * @param string           $directory  Carpeta del archivo de vencimientos.
     * @param SluggerInterface $slugger    Para limpiar el nombre original.
     * @param Filesystem       $filesystem Para borrar.
     */
    public function __construct(
        #[Autowire('%app.obligation_documents_dir%')]
        string $directory,
        SluggerInterface $slugger,
        Filesystem $filesystem,
    ) {
        parent::__construct($directory, $slugger, $filesystem);
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
}
