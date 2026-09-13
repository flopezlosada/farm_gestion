<?php

namespace App\Form;

use App\Service\Obligation\ObligationDocumentStore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * El campo «sube el papel», con los mismos límites en los tres sitios donde se
 * sube un documento de vencimientos: el alta, la edición de la ficha y cada
 * periodo del historial.
 *
 * Existe para que la lista de formatos y el tamaño máximo estén escritos una
 * sola vez. Tres copias de una lista de MIME types acaban siendo tres listas
 * distintas, y la que se queda corta sólo se descubre cuando alguien no puede
 * subir su convenio.
 *
 * Nunca va mapeado a la entidad: en la fila se guarda el nombre del fichero ya
 * archivado, no el fichero que llega del formulario.
 */
class ObligationDocumentType extends AbstractType
{
    public function getParent(): string
    {
        return FileType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'mapped' => false,
            'constraints' => [ObligationDocumentStore::constraint()],
            // Lo que el selector de ficheros ofrece por defecto. Sale de la
            // misma lista que valida el servidor: dos listas acaban diciendo
            // cosas distintas, y la que engaña es siempre ésta.
            'attr' => [
                'accept' => implode(',', ObligationDocumentStore::MIME_TYPES),
            ],
        ]);
    }
}
