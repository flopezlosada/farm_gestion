<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use App\Form\BlogEditType;
use App\Form\BlogSecondType;
use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;

use App\Entity\Blog;
use App\Form\BlogType;
use WhiteOctober\BreadcrumbsBundle\Model\Breadcrumbs;

/**
 * Blog controller.
 *
 */
class BlogController extends AbstractAppController
{

    /**
     * Lists all Blog entities.
     * @param category_id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    /*public function index($category_id)
    {
        $em = $this->getDoctrine()->getManager();

        $category = $em->getRepository(\App\Entity\Category::class)->find($category_id);
        $entities = $em->getRepository(\App\Entity\Blog::class)->findByCategory($category);

        return $this->render('Blog/index.html.twig', array(
            'entities' => $entities,
            'category' => $category
        ));
    }*/

    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Blog::class)->findAll();

        return $this->render('Blog/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    public function frontend_index(EntityManagerInterface $em, Request $request, PaginatorInterface $paginator, Breadcrumbs $breadcrumbs)
    {

        $blogs_repository = $em->getRepository(Blog::class);


        $dql = "select b from App\\Entity\\Blog b order by b.created desc";

        $query = $em->createQuery($dql);

        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );

        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));


        return $this->render('Blog/frontend_index.html.twig', array(
            'entities' => $pagination,
        ));

    }

    /**
     * Creates a new Blog entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Blog();
        $em = $this->getDoctrine()->getManager();

        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('blog_second_step', array('id' => $entity->getId())));
        } else {
            ladybug_dump($form->getErrors());
        }
        return $this->render('Blog/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));

    }


    /**
     * Creates a form to create a Blog entity.
     *
     * @param Blog $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Blog $entity)
    {
        $form = $this->createForm(BlogType::class, $entity, array(
            'action' => $this->generateUrl('blog_create'),
            'method' => 'POST',
        ));
        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }


    /**
     * Displays a form to create a new Blog entity.
     *
     */
    public function new()
    {
        $entity = new Blog();
        $em = $this->getDoctrine()->getManager();


        $form = $this->createCreateForm($entity);

        return $this->render('Blog/new.html.twig', array(
            'entity' => $entity,

            'form' => $form->createView(),
        ));
    }


    public function second($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);
        $galleries = $em->getRepository(\App\Entity\Blog::class)->findMedia("Gallery", $id, "blog");
        $audios = $em->getRepository(\App\Entity\Blog::class)->findMedia("Audio", $id, "blog");
        $videos = $em->getRepository(\App\Entity\Blog::class)->findMedia("Video", $id, "blog");
        $documents = $em->getRepository(\App\Entity\Blog::class)->findMedia("Document", $id, "blog");

        $form = $this->createSecondForm($entity);

        return $this->render('Blog/second.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'galleries' => $galleries,
            'audios' => $audios,
            'videos' => $videos,
            'documents' => $documents
        ));
    }


    private function createSecondForm(Blog $entity)
    {
        $form = $this->createForm(BlogSecondType::class, $entity, array(
            'action' => $this->generateUrl('blog_update_second', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));
        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    public function updateSecond(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createSecondForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('blog_show', array('slug' => $entity->getSlug())));
        } else {
            ladybug_dump($editForm->getErrors());
        }
        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }


    /**
     * Finds and displays a Blog entity.
     *
     */
    public function show($slug, Breadcrumbs $breadcrumbs)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Blog::class)->findPostsBySlug($slug);


        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));
        $breadcrumbs->addItem($entity->getTitle(), "");

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($entity->getId());

        return $this->render('Blog/show.html.twig', array(
            'post' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Blog entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Blog entity.
     *
     * @param Blog $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Blog $entity)
    {
        $form = $this->createForm(BlogEditType::class, $entity, array(
            'action' => $this->generateUrl('blog_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Blog entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $entity->setModified(1);
            $em->persist($entity);
            $entity->setModified(0);
            $em->flush();

            return $this->redirect($this->generateUrl('blog_show', array('slug' => $entity->getSlug())));
        } else {
            ladybug_dump($editForm->getErrors());
        }

        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Blog entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Blog entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('blog'));
    }

    /**
     * Creates a form to delete a Blog entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('blog_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }


    public function edition($id, $object_class)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App\Entity\\' . ucfirst($object_class))->find($id);
        $single_images = $em->getRepository(\App\Entity\Blog::class)->findMedia("Image", $id, $object_class, 1);
        $grouped_images = $em->getRepository(\App\Entity\Blog::class)->findMedia("Image", $id, $object_class, 0);
        $audios = $em->getRepository(\App\Entity\Blog::class)->findMedia("Audio", $id, $object_class);
        $videos = $em->getRepository(\App\Entity\Blog::class)->findMedia("Video", $id, $object_class);
        $documents = $em->getRepository(\App\Entity\Blog::class)->findMedia("Document", $id, $object_class);


        return $this->render('Blog/edition.html.twig', array(
            'entity' => $entity,
            'single_images' => $single_images,
            'grouped_images' => $grouped_images,
            'audios' => $audios,
            'videos' => $videos,
            'documents' => $documents,
            'object_class' => $object_class
        ));
    }


    public function categories()
    {
    }

    public function show_category($id,Request $request, PaginatorInterface $paginator, Breadcrumbs $breadcrumbs)
    {
        $em = $this->getDoctrine()->getManager();

        $category = $em->getRepository(\App\Entity\Category::class)->find($id);


        $em = $this->getDoctrine()->getManager();
        $dql = "select b from App\\Entity\\Blog b  where b.category=:category order by b.created desc";
        $query = $em->createQuery($dql);
        $query->setParameter("category", $category);

        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );




        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));

        return $this->render("Blog/frontend_index.html.twig", array(
            'category' => $category,
            'entities' => $pagination,
        ));

    }

    public function latest_post()
    {
        $em = $this->getDoctrine()->getManager();
        $entities = $em->getRepository(\App\Entity\Blog::class)->findLatest(3, 1);

        return $this->render("Blog/latest_post.html.twig", array(
            'entities' => $entities,
        ));
    }
}
