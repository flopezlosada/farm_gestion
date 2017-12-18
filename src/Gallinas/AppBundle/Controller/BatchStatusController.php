<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\BatchStatus;
use Gallinas\AppBundle\Form\BatchStatusType;

/**
 * BatchStatus controller.
 *
 */
class BatchStatusController extends Controller
{

    /**
     * Lists all BatchStatus entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:BatchStatus')->findAll();

        return $this->render('AppBundle:BatchStatus:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new BatchStatus entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new BatchStatus();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('batchstatus_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:BatchStatus:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a BatchStatus entity.
     *
     * @param BatchStatus $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(BatchStatus $entity)
    {
        $form = $this->createForm(new BatchStatusType(), $entity, array(
            'action' => $this->generateUrl('batchstatus_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new BatchStatus entity.
     *
     */
    public function newAction()
    {
        $entity = new BatchStatus();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:BatchStatus:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a BatchStatus entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:BatchStatus')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:BatchStatus:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing BatchStatus entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:BatchStatus')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:BatchStatus:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a BatchStatus entity.
    *
    * @param BatchStatus $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(BatchStatus $entity)
    {
        $form = $this->createForm(new BatchStatusType(), $entity, array(
            'action' => $this->generateUrl('batchstatus_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing BatchStatus entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:BatchStatus')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('batchstatus_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:BatchStatus:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a BatchStatus entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:BatchStatus')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find BatchStatus entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('batchstatus'));
    }

    /**
     * Creates a form to delete a BatchStatus entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('batchstatus_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
