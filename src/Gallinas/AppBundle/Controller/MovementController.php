<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Movement;
use Gallinas\AppBundle\Form\MovementType;

/**
 * Movement controller.
 *
 */
class MovementController extends Controller
{

    /**
     * Lists all Movement entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Movement')->findAll();

        return $this->render('AppBundle:Movement:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Movement entity.
     *
     */
    public function createAction($batch_id,Request $request)
    {
        $entity = new Movement();
        $em = $this->getDoctrine()->getManager();
        $batch = $em->getRepository("AppBundle:Batch")->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $entity->setDate(new \DateTime($entity->getDate()));
            $em = $this->getDoctrine()->getManager();
            $entity->setBatch($batch);
            $em->persist($entity);
            $last_movement=$em->getRepository("AppBundle:Movement")->findLastMovement($batch_id);
            if (count($last_movement)>0)
            {
                $interval=date_diff($last_movement[0]->getDate(),$entity->getDate(),false);
                $last_movement[0]->setAmount($interval->format('%R%a'));
                $em->persist($last_movement[0]);
            }

            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $batch_id)));
        }

        return $this->render('AppBundle:Movement:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
            'batch' => $batch,
        ));
    }

    /**
     * Creates a form to create a Movement entity.
     *
     * @param Movement $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Movement $entity, $batch)
    {
        $form = $this->createForm(new MovementType($batch), $entity, array(
            'action' => $this->generateUrl('movement_create', array('batch_id' => $batch->getId())),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Movement entity.
     *
     */
    public function newAction($batch_id)
    {
        $entity = new Movement();
        $em = $this->getDoctrine()->getManager();
        $batch = $em->getRepository("AppBundle:Batch")->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);


        return $this->render('AppBundle:Movement:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
            'batch' => $batch,
        ));
    }

    /**
     * Finds and displays a Movement entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Movement')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Movement:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Movement entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Movement')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Movement:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Movement entity.
    *
    * @param Movement $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Movement $entity)
    {
        $form = $this->createForm(new MovementType(), $entity, array(
            'action' => $this->generateUrl('movement_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Movement entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Movement')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('movement_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Movement:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Movement entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Movement')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Movement entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('movement'));
    }

    /**
     * Creates a form to delete a Movement entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('movement_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
