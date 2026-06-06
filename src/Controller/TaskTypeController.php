<?php

namespace App\Controller;

use App\Entity\TaskType;
use App\Form\TaskTypeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TaskType controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class TaskTypeController extends AbstractController
{

    /**
     * Lists all TaskType entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\TaskType::class)->findAll();

        return $this->render('TaskType/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new TaskType entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new TaskType();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('tasktype_show', array('id' => $entity->getId())));
        }

        return $this->render('TaskType/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a TaskType entity.
     *
     * @param TaskType $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(TaskType $entity)
    {
        $form = $this->createForm(TaskTypeType::class, $entity, array(
            'action' => $this->generateUrl('tasktype_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new TaskType entity.
     *
     */
    public function new()
    {
        $entity = new TaskType();
        $form = $this->createCreateForm($entity);

        return $this->render('TaskType/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a TaskType entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskType::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskType entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskType/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing TaskType entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskType::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskType entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('TaskType/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a TaskType entity.
     *
     * @param TaskType $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(TaskType $entity)
    {
        $form = $this->createForm(TaskTypeType::class, $entity, array(
            'action' => $this->generateUrl('tasktype_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing TaskType entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\TaskType::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find TaskType entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('tasktype_show', array('id' => $id)));
        }

        return $this->render('TaskType/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a TaskType entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\TaskType::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find TaskType entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('tasktype'));
    }

    /**
     * Creates a form to delete a TaskType entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('tasktype_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
