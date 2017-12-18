<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Labour;
use Gallinas\AppBundle\Form\LabourType;

/**
 * Labour controller.
 *
 */
class LabourController extends Controller
{

    /**
     * Lists all Labour entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Labour')->findAll();

        return $this->render('AppBundle:Labour:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Labour entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Labour();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('labour_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Labour:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Labour entity.
     *
     * @param Labour $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Labour $entity)
    {
        $form = $this->createForm(new LabourType(), $entity, array(
            'action' => $this->generateUrl('labour_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Labour entity.
     *
     */
    public function newAction()
    {
        $entity = new Labour();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Labour:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Labour entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Labour')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Labour entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Labour:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Labour entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Labour')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Labour entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Labour:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Labour entity.
    *
    * @param Labour $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Labour $entity)
    {
        $form = $this->createForm(new LabourType(), $entity, array(
            'action' => $this->generateUrl('labour_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Labour entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Labour')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Labour entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('labour_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Labour:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Labour entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Labour')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Labour entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('labour'));
    }

    /**
     * Creates a form to delete a Labour entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('labour_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
