<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\TaskImage;
use Gallinas\AppBundle\Form\TaskImageType;

/**
 * TaskImage controller.
 *
 */
class TaskImageController extends Controller
{

    /**
     * Lists all TaskImage entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:TaskImage')->findAll();

        return $this->render('AppBundle:TaskImage:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new TaskImage entity.
     *
     */
    public function createAction(Request $request,$task_id)
    {
        $entity = new TaskImage();
        $form = $this->createCreateForm($entity,$task_id);
        $form->handleRequest($request);
        $em = $this->getDoctrine()->getManager();

        $task = $em->getRepository('AppBundle:Task')->find($task_id);
        $entity->setTask($task);
        if ($form->isValid()) {

            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task_show', array('id' => $task->getId())));
        }

        return $this->render('AppBundle:TaskImage:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a TaskImage entity.
     *
     * @param TaskImage $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(TaskImage $entity,$task_id)
    {
        $form = $this->createForm(new TaskImageType(), $entity, array(
            'action' => $this->generateUrl('taskimage_create',array('task_id'=>$task_id)),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new TaskImage entity.
     *
     */
    public function newAction($task_id)
    {
        $entity = new TaskImage();
        $form   = $this->createCreateForm($entity,$task_id);
        $em = $this->getDoctrine()->getManager();

        $task = $em->getRepository('AppBundle:Task')->find($task_id);
        $entity->setTask($task);
        return $this->render('AppBundle:TaskImage:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a TaskImage entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:TaskImage')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:TaskImage:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing TaskImage entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:TaskImage')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:TaskImage:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a TaskImage entity.
    *
    * @param TaskImage $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(TaskImage $entity)
    {
        $form = $this->createForm(new TaskImageType(), $entity, array(
            'action' => $this->generateUrl('taskimage_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing TaskImage entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:TaskImage')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('taskimage_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:TaskImage:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a TaskImage entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:TaskImage')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find TaskImage entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('taskimage'));
    }

    /**
     * Creates a form to delete a TaskImage entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('taskimage_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
