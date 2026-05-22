<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Video;
use App\Form\VideoType;

/**
 * Video controller.
 *
 * Sólo flujos vivos: subida desde el aside AJAX de Blog/edition,
 * borrado rápido desde el mismo aside, y renderizado del snippet
 * en el frontend público del blog. El CRUD standalone (index/show/
 * edit/update/delete) se retiró por código muerto.
 */
#[IsGranted('ROLE_BLOG')]
class VideoController extends AbstractAppController
{
    /**
     * Procesa el alta de un Video asociado a una entidad anfitriona
     * (object_class + foreign_key). Disparado desde el modal AJAX
     * del aside del editor de posts.
     */
    public function create(Request $request, $foreign_key, $object_class)
    {
        $entity = new Video();
        $form = $this->createCreateForm($entity, $foreign_key, $object_class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setForeignKey($foreign_key);
            $entity->setObjectClass($object_class);
            $em->persist($entity);
            $em->flush();

            if ($request->isXmlHttpRequest()) {
                return $this->redirect($this->generateUrl($entity->getObjectClass() . "_edition", array('id' => $foreign_key, 'object_class' => $entity->getObjectClass())));
            }
            return $this->redirect($this->generateUrl($entity->getObjectClass() . '_show', array('id' => $entity->getForeignKey())));
        }

        return $this->render('Video/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Construye el form de creación del Video.
     */
    private function createCreateForm(Video $entity, $foreign_key, $object_class)
    {
        $form = $this->createForm(VideoType::class, $entity, array(
            'action' => $this->generateUrl('video_create', array('foreign_key' => $foreign_key, 'object_class' => $object_class)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Renderiza el form de alta de Video para el modal AJAX disparado
     * desde el aside del editor de posts.
     */
    public function new($foreign_key, $object_class)
    {
        $entity = new Video();
        $form = $this->createCreateForm($entity, $foreign_key, $object_class);

        return $this->render('Video/new.html.twig', array(
            'entity' => $entity,
            'foreign_key' => $foreign_key,
            'object_class' => $object_class,
            'form' => $form->createView(),
        ));
    }

    /**
     * Snippet inline para el frontend público del blog. Lo invoca
     * AppExtension al expandir los shortcodes [[insert_media_video_<id>]]
     * dentro del cuerpo de un post.
     */
    public function show_snippet($id)
    {
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository(\App\Entity\Video::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Video entity.');
        }

        return $this->render('Video/show_snippet.html.twig', array(
            'entity' => $entity,
        ));
    }

    /**
     * Borrado directo del Video desde el aside del editor de posts.
     */
    public function fastDelete($id)
    {
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository(\App\Entity\Video::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Video entity.');
        }

        $url = $this->generateUrl($entity->getObjectClass() . "_edit", array('id' => $entity->getForeignKey()));

        $em->remove($entity);
        $em->flush();

        return $this->redirect($url);
    }
}
