<?php

namespace App\Controller;

use App\Entity\TaskImage;
use App\Form\TaskImageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TaskImage controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class TaskImageController extends AbstractController
{

    /**
     * Lists all TaskImage entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\TaskImage::class)->findAll();

        return $this->render('TaskImage/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new TaskImage entity.
     *
     */
    public function create(Request $request, $task_id, EntityManagerInterface $em)
    {
        $entity = new TaskImage();
        $form = $this->createCreateForm($entity, $task_id);
        $form->handleRequest($request);

        $task = $em->getRepository(\App\Entity\Task::class)->find($task_id);
        $entity->setTask($task);
        if ($form->isSubmitted() && $form->isValid()) {

            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task_show', array('id' => $task->getId())));
        }

        return $this->render('TaskImage/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a TaskImage entity.
     *
     * @param TaskImage $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(TaskImage $entity, $task_id)
    {
        $form = $this->createForm(TaskImageType::class, $entity, array(
            'action' => $this->generateUrl('taskimage_create', array('task_id' => $task_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new TaskImage entity.
     *
     */
    public function new($task_id, EntityManagerInterface $em)
    {
        $entity = new TaskImage();
        $form = $this->createCreateForm($entity, $task_id);

        $task = $em->getRepository(\App\Entity\Task::class)->find($task_id);
        $entity->setTask($task);
        return $this->render('TaskImage/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a TaskImage entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskImage::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskImage/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing TaskImage entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskImage::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskImage/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
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
        $form = $this->createForm(TaskImageType::class, $entity, array(
            'action' => $this->generateUrl('taskimage_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing TaskImage entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskImage::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskImage entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('taskimage_show', array('id' => $id)));
        }

        return $this->render('TaskImage/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a TaskImage entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\TaskImage::class)->find($id);

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
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
