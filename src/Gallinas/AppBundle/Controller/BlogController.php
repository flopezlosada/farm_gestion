<?php

namespace Gallinas\AppBundle\Controller;

use Gallinas\AppBundle\Form\BlogEditType;
use Gallinas\AppBundle\Form\BlogSecondType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Blog;
use Gallinas\AppBundle\Form\BlogType;

/**
 * Blog controller.
 *
 */
class BlogController extends Controller
{

    /**
     * Lists all Blog entities.
     * @param category_id
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    /*public function indexAction($category_id)
    {
        $em = $this->getDoctrine()->getManager();

        $category = $em->getRepository('AppBundle:Category')->find($category_id);
        $entities = $em->getRepository('AppBundle:Blog')->findByCategory($category);

        return $this->render('AppBundle:Blog:index.html.twig', array(
            'entities' => $entities,
            'category' => $category
        ));
    }*/

    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Blog')->findAll();

        return $this->render('AppBundle:Blog:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    public function frontend_indexAction()
    {
        $em = $this->getDoctrine()->getManager();
        $dql = "select b from AppBundle:Blog b order by b.created desc";
        $query = $em->createQuery($dql);

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $this->get('request')->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );

        $breadcrumbs = $this->get("white_october_breadcrumbs");
        $breadcrumbs->addItem("Home", $this->get("router")->generate("homepage"));
        $breadcrumbs->addItem("Blog", $this->get("router")->generate("frontend_blog"));


        return $this->render('AppBundle:Blog:frontend_index.html.twig', array(
            'entities' => $pagination,
        ));

    }

    /**
     * Creates a new Blog entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Blog();
        $em = $this->getDoctrine()->getManager();

        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);
        if ($form->isValid())
        {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('blog_second_step', array('id' => $entity->getId())));
        } else
        {
            ladybug_dump($form->getErrors());
        }
        return $this->render('AppBundle:Blog:new.html.twig', array(
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
        $form = $this->createForm(new BlogType(), $entity, array(
            'action' => $this->generateUrl('blog_create'),
            'method' => 'POST',
        ));
        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }


    /**
     * Displays a form to create a new Blog entity.
     *
     */
    public function newAction()
    {
        $entity = new Blog();
        $em = $this->getDoctrine()->getManager();


        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Blog:new.html.twig', array(
            'entity' => $entity,

            'form' => $form->createView(),
        ));
    }


    public function secondAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Blog')->find($id);
        $galleries = $em->getRepository('AppBundle:Blog')->findMedia("Gallery", $id, "blog");
        $audios = $em->getRepository('AppBundle:Blog')->findMedia("Audio", $id, "blog");
        $videos = $em->getRepository('AppBundle:Blog')->findMedia("Video", $id, "blog");
        $documents = $em->getRepository('AppBundle:Blog')->findMedia("Document", $id, "blog");

        $form = $this->createSecondForm($entity);

        return $this->render('AppBundle:Blog:second.html.twig', array(
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
        $form = $this->createForm(new BlogSecondType(), $entity, array(
            'action' => $this->generateUrl('blog_update_second', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));
        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    public function updateSecondAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Blog')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createSecondForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $em->flush();

            return $this->redirect($this->generateUrl('blog_show', array('slug' => $entity->getSlug())));
        } else
        {
            ladybug_dump($editForm->getErrors());
        }
        return $this->render('AppBundle:Blog:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }


    /**
     * Finds and displays a Blog entity.
     *
     */
    public function showAction($slug)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Blog')->findPostsBySlug($slug);

        $breadcrumbs = $this->get("white_october_breadcrumbs");
        $breadcrumbs->addItem("Home", $this->get("router")->generate("homepage"));
        $breadcrumbs->addItem("Blog", $this->get("router")->generate("frontend_blog"));
        $breadcrumbs->addItem($entity->getTitle(), "");

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($entity->getId());

        return $this->render('AppBundle:Blog:show.html.twig', array(
            'post' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Blog entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Blog')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Blog:edit.html.twig', array(
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
        $form = $this->createForm(new BlogEditType(), $entity, array(
            'action' => $this->generateUrl('blog_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Blog entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Blog')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $entity->setModified(1);
            $em->persist($entity);
            $entity->setModified(0);
            $em->flush();

            return $this->redirect($this->generateUrl('blog_show', array('slug' => $entity->getSlug())));
        } else
        {
            ladybug_dump($editForm->getErrors());
        }

        return $this->render('AppBundle:Blog:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Blog entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Blog')->find($id);

            if (!$entity)
            {
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
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }


    public function editionAction($id, $object_class)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:' . ucfirst($object_class))->find($id);
        $single_images = $em->getRepository('AppBundle:Blog')->findMedia("Image", $id, $object_class, 1);
        $grouped_images = $em->getRepository('AppBundle:Blog')->findMedia("Image", $id, $object_class, 0);
        $audios = $em->getRepository('AppBundle:Blog')->findMedia("Audio", $id, $object_class);
        $videos = $em->getRepository('AppBundle:Blog')->findMedia("Video", $id, $object_class);
        $documents = $em->getRepository('AppBundle:Blog')->findMedia("Document", $id, $object_class);


        return $this->render('AppBundle:Blog:edition.html.twig', array(
            'entity' => $entity,
            'single_images' => $single_images,
            'grouped_images' => $grouped_images,
            'audios' => $audios,
            'videos' => $videos,
            'documents' => $documents,
            'object_class' => $object_class
        ));
    }


    public function categoriesAction()
    {
    }

    public function show_categoryAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $category = $em->getRepository('AppBundle:Category')->find($id);


        $em = $this->getDoctrine()->getManager();
        $dql = "select b from AppBundle:Blog b  where b.category=:category order by b.created desc";
        $query = $em->createQuery($dql);
        $query->setParameter("category", $category);

        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $query,
            $this->get('request')->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );

        $breadcrumbs = $this->get("white_october_breadcrumbs");
        $breadcrumbs->addItem("Home", $this->get("router")->generate("homepage"));
        $breadcrumbs->addItem("Blog", $this->get("router")->generate("frontend_blog"));

        return $this->render("AppBundle:Blog:frontend_index.html.twig", array(
            'category' => $category,
            'entities' => $pagination,
        ));

    }

    public function latest_postAction()
    {
        $em = $this->getDoctrine()->getManager();
        $entities = $em->getRepository("AppBundle:Blog")->findLatest(3, 1);

        return $this->render("AppBundle:Blog:latest_post.html.twig", array(
            'entities' => $entities,
        ));
    }
}
