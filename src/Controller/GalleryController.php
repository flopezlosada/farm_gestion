<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gallery controller.
 *
 * La tabla `gallery` está vacía en producción (0 registros) y el
 * concepto "galería como entidad" nunca llegó a usarse: las imágenes
 * agrupadas viven en `Image` con `single=0` y se renderizan vía
 * `show_snippet` directamente. Por eso todo el CRUD de Gallery
 * (index/show/edit/update/delete/new/create/newSingle/imageList)
 * se retiró por código muerto. Sólo queda el snippet, que sí lo
 * invoca `AppExtension` al expandir shortcodes en el blog público.
 */
class GalleryController extends AbstractController
{
    /**
     * Snippet inline para el frontend público del blog. Lo invoca
     * AppExtension al expandir los shortcodes [[insert_media_gallery_<id>]]
     * dentro del cuerpo de un post.
     *
     * La galería renderiza todas las imágenes del post agrupadas
     * (single=0), no las imágenes de una entidad `Gallery`.
     */
    public function show_snippet($id, $object_class, EntityManagerInterface $em)
    {
        $blog = $em->getRepository(\App\Entity\Blog::class)->find($id);
        $images = $em->getRepository(\App\Entity\Image::class)->findBlogGroupedImages($id, $object_class);

        return $this->render('Gallery/show_snippet.html.twig', array(
            'images' => $images,
            'blog' => $blog,
        ));
    }
}
