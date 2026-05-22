<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Image;
use App\Form\ImageType;

/**
 * Image controller.
 *
 * Sólo flujos vivos: subida desde el aside AJAX de Blog/edition
 * (con `single` distinguiendo imagen suelta vs. dentro de galería),
 * borrado rápido desde el mismo aside, y renderizado del snippet
 * en el frontend público del blog. El CRUD standalone (index/show/
 * edit/update/delete) y el flujo `image_in_gallery_*` se retiraron
 * por código muerto.
 */
#[IsGranted('ROLE_BLOG')]
class ImageController extends AbstractAppController
{
    /**
     * Procesa el alta de una Image asociada a una entidad anfitriona
     * (object_class + foreign_key + single). Disparado desde el modal
     * AJAX del aside del editor de posts.
     */
    public function create(Request $request, $foreign_key, $object_class, $single)
    {
        $entity = new Image();
        $form = $this->createCreateForm($entity, $foreign_key, $object_class, $single);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setForeignKey($foreign_key);
            $entity->setObjectClass($object_class);
            $entity->setSingle($single);
            $em->persist($entity);
            $em->flush();

            if ($request->isXmlHttpRequest()) {
                return $this->redirect($this->generateUrl($entity->getObjectClass() . "_edition", array('id' => $foreign_key, 'object_class' => $entity->getObjectClass())));
            }
            return $this->redirect($this->generateUrl($entity->getObjectClass() . '_show', array('id' => $entity->getForeignKey())));
        }

        return $this->render('Image/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'foreign_key' => $foreign_key,
            'object_class' => $object_class,
        ));
    }

    /**
     * Construye el form de creación de la Image.
     */
    private function createCreateForm(Image $entity, $foreign_key, $object_class, $single)
    {
        $form = $this->createForm(ImageType::class, $entity, array(
            'action' => $this->generateUrl('image_create', array('foreign_key' => $foreign_key, 'object_class' => $object_class, 'single' => $single)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Renderiza el form de alta de Image para el modal AJAX disparado
     * desde el aside del editor de posts.
     */
    public function new($foreign_key, $object_class, $single)
    {
        $entity = new Image();
        $form = $this->createCreateForm($entity, $foreign_key, $object_class, $single);

        return $this->render('Image/new.html.twig', array(
            'entity' => $entity,
            'foreign_key' => $foreign_key,
            'single' => $single,
            'object_class' => $object_class,
            'form' => $form->createView(),
        ));
    }

    /**
     * Borrado directo de la Image desde el aside del editor de posts.
     */
    public function fastDelete($id)
    {
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository(\App\Entity\Image::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Image entity.');
        }

        $url = $this->generateUrl($entity->getObjectClass() . "_edit", array('id' => $entity->getForeignKey()));

        $em->remove($entity);
        $em->flush();

        return $this->redirect($url);
    }

    /**
     * Snippet inline para el frontend público del blog. Lo invoca
     * AppExtension al expandir los shortcodes [[insert_media_image_<id>]]
     * dentro del cuerpo de un post.
     */
    public function show_snippet($id)
    {
        $em = $this->getDoctrine()->getManager();
        $image = $em->getRepository(\App\Entity\Image::class)->find($id);

        return $this->render('Image/show_snippet.html.twig', array(
            'image' => $image,
        ));
    }
}
