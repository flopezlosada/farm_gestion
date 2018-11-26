<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Basket;
use Gallinas\AppBundle\Form\BasketType;

/**
 * Basket controller.
 *
 */
class BasketController extends Controller
{

    /**
     * Lists all Basket entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Basket')->findAll();

        return $this->render('AppBundle:Basket:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Basket entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Basket();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('basket_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Basket:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Basket entity.
     *
     * @param Basket $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Basket $entity)
    {
        $form = $this->createForm(new BasketType(), $entity, array(
            'action' => $this->generateUrl('basket_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Basket entity.
     *
     */
    public function newAction()
    {
        $entity = new Basket();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Basket:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Basket entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Basket')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Basket entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Basket:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Basket entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Basket')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Basket entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Basket:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Basket entity.
    *
    * @param Basket $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Basket $entity)
    {
        $form = $this->createForm(new BasketType(), $entity, array(
            'action' => $this->generateUrl('basket_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Basket entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Basket')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Basket entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('basket_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Basket:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Basket entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Basket')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Basket entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('basket'));
    }

    /**
     * Creates a form to delete a Basket entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('basket_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
