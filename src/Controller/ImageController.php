<?php

namespace App\Controller;

use App\Entity\Image;
use App\Form\ImageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
class ImageController extends AbstractController
{
    /**
     * Procesa el alta de una Image asociada a una entidad anfitriona
     * (object_class + foreign_key + single). Disparado desde el modal
     * AJAX del aside del editor de posts.
     */
    #[IsGranted('ROLE_BLOG')]
    public function create(Request $request, $foreign_key, $object_class, $single, EntityManagerInterface $em)
    {
        $entity = new Image();
        $form = $this->createCreateForm($entity, $foreign_key, $object_class, $single);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setForeignKey($foreign_key);
            $entity->setObjectClass($object_class);
            $entity->setSingle($single);
            $em->persist($entity);
            $em->flush();
        } elseif ($form->isSubmitted()) {
            // Form enviado pero rebotó por validación (típicamente
            // Assert\File maxSize=6MB). Acumulamos los mensajes en
            // una flash y redirigimos al _edition igual que en el
            // caso de éxito, así el aside se reinjecta limpio con
            // el callout de error en lugar de quedarse "comido" por
            // el HTML del form re-renderizado.
            $this->addFlash('error', $this->buildUploadError($form));
        } else {
            // post_max_size excedido u otra causa por la que PHP
            // descarta el body antes de llegar al form.
            $this->addFlash('error', 'No se recibió el archivo. Asegúrate de que no excede el tamaño máximo permitido.');
        }

        // El único caller de esta acción es el aside AJAX de Blog/edition.
        // jquery.form usa iframe para uploads con archivo, así que la
        // petición no lleva X-Requested-With y isXmlHttpRequest() es false;
        // por eso redirigimos siempre al fragment _edition (no a _show),
        // que es el que recompone el aside.
        return $this->redirect($this->generateUrl($object_class . '_edition', array(
            'id' => $foreign_key,
            'object_class' => $object_class,
        )));
    }

    /**
     * Aplana los errores de validación del form en un único mensaje
     * legible para mostrar como flash en el aside.
     */
    private function buildUploadError($form): string
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }
        return $errors
            ? 'No se pudo subir el archivo: ' . implode(' ', $errors)
            : 'No se pudo subir el archivo. Asegúrate de que no excede 6 MB.';
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
    #[IsGranted('ROLE_BLOG')]
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
    #[IsGranted('ROLE_BLOG')]
    public function fastDelete($id, EntityManagerInterface $em)
    {
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
    public function show_snippet($id, EntityManagerInterface $em)
    {
        $image = $em->getRepository(\App\Entity\Image::class)->find($id);

        // La imagen referenciada en el cuerpo de un post puede haber sido
        // borrada (referencia rota). En ese caso no renderizamos el snippet
        // en vez de tumbar el post entero con un error 500.
        if (!$image) {
            return new Response('');
        }

        return $this->render('Image/show_snippet.html.twig', array(
            'image' => $image,
        ));
    }
}
