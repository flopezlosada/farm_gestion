<?php

namespace App\Service\Accounting\Invoice;

use App\Service\Storage\PrivateFileStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\File;

/**
 * El archivo de las facturas recibidas. Llevan CIF, direcciones y a veces los
 * últimos dígitos de una tarjeta, así que viven fuera del docroot y salen sólo por
 * {@see \App\Controller\ReceivedInvoiceController}, con el permiso de contabilidad.
 */
final class InvoiceFileStore extends PrivateFileStore
{
    /**
     * Por debajo de los 15 MB que admite el hosting por subida: un fichero que PHP
     * recorta llega como si no se hubiera enviado nada. Una factura pesa KB y una
     * foto de móvil 2-5 MB.
     */
    public const MAX_SIZE = '12M';

    /**
     * Lo que se acepta guardar, comprobado por el contenido y no por la extensión.
     * Más amplio que lo que se lee solo: un DOCX se guarda y se completa a mano.
     */
    public const MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * @param string           $directory  Carpeta del archivo de facturas.
     * @param SluggerInterface $slugger    Para limpiar el nombre original.
     * @param Filesystem       $filesystem Para borrar.
     */
    public function __construct(
        #[Autowire('%app.invoice_files_dir%')]
        string $directory,
        SluggerInterface $slugger,
        Filesystem $filesystem,
    ) {
        parent::__construct($directory, $slugger, $filesystem);
    }

    /**
     * Regla de validación de una factura subida.
     *
     * @return File Restricción de tamaño y formato.
     */
    public static function constraint(): File
    {
        return new File(
            maxSize: self::MAX_SIZE,
            mimeTypes: self::MIME_TYPES,
            maxSizeMessage: 'La factura pesa demasiado ({{ size }} {{ suffix }}). El máximo son {{ limit }} {{ suffix }}.',
            mimeTypesMessage: 'Ese formato no se puede guardar. Vale un PDF, una foto (JPG, PNG, HEIC) o un documento (DOCX, ODT, XLSX, ODS).',
        );
    }
}
