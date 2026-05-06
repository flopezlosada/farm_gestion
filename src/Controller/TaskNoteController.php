<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use App\Entity\TaskNote;
use App\Form\TaskNoteType;

/**
 * TaskNote controller.
 *
 */
class TaskNoteController extends AbstractController
{

    /**
     * Lists all TaskNote entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\TaskNote::class)->findAll();

        return $this->render('TaskNote/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new TaskNote entity.
     *
     */
    public function create(Request $request, $task_id)
    {
        $entity = new TaskNote();
        $form = $this->createCreateForm($entity, $task_id);
        $form->handleRequest($request);
        $em = $this->getDoctrine()->getManager();

        $task = $em->getRepository(\App\Entity\Task::class)->find($task_id);
        $entity->setTask($task);
        if ($form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task_show', array('id' => $task->getId())));
        }

        return $this->render('TaskNote/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a TaskNote entity.
     *
     * @param TaskNote $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(TaskNote $entity, $task_id)
    {
        $form = $this->createForm(TaskNoteType::class, $entity, array(
            'action' => $this->generateUrl('tasknote_create', array('task_id' => $task_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new TaskNote entity.
     *
     */
    public function new($task_id)
    {
        $entity = new TaskNote();
        $form = $this->createCreateForm($entity, $task_id);
        $em = $this->getDoctrine()->getManager();

        $task = $em->getRepository(\App\Entity\Task::class)->find($task_id);
        $entity->setTask($task);

        return $this->render('TaskNote/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a TaskNote entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\TaskNote::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskNote entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskNote/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing TaskNote entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\TaskNote::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskNote entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskNote/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a TaskNote entity.
     *
     * @param TaskNote $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(TaskNote $entity)
    {
        $form = $this->createForm(TaskNoteType::class, $entity, array(
            'action' => $this->generateUrl('tasknote_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing TaskNote entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\TaskNote::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskNote entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('task_show', array('id' => $entity->getTask()->getId())));
        }

        return $this->render('TaskNote/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a TaskNote entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\TaskNote::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find TaskNote entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('tasknote'));
    }

    /**
     * Creates a form to delete a TaskNote entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('tasknote_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
