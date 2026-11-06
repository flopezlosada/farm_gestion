<?php

namespace App\Entity;

/**
 * Borrado del fichero subido que acompaña a una entidad cuando ésta se elimina.
 *
 * Se llama desde el callback `@PostRemove`, que corre DENTRO de la transacción
 * del flush: si el borrado lanzara, la fila no se eliminaría. Por eso un fichero
 * que ya no está en disco (subida perdida, copia local sin `uploads/`) no es un
 * error: el objetivo —que el fichero no exista— ya se cumple.
 */
trait RemovesUploadedFile
{
    /**
     * Borra el fichero de la ruta dada si existe; sin ruta o sin fichero no hace nada.
     *
     * @param string|null $path ruta absoluta del fichero subido, o null si la entidad no tiene
     */
    protected function deleteUploadedFile(?string $path): void
    {
        if (null !== $path && is_file($path)) {
            unlink($path);
        }
    }
}
