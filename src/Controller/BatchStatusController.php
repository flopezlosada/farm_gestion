<?php

namespace App\Controller;

use App\Entity\BatchStatus;
use App\Form\BatchStatusType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BatchStatus controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class BatchStatusController extends AbstractController
{

    /**
     * Lists all BatchStatus entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\BatchStatus::class)->findAll();

        return $this->render('BatchStatus/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new BatchStatus entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new BatchStatus();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('batchstatus_show', array('id' => $entity->getId())));
        }

        return $this->render('BatchStatus/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
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
        $form = $this->createForm(BatchStatusType::class, $entity, array(
            'action' => $this->generateUrl('batchstatus_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new BatchStatus entity.
     *
     */
    public function new()
    {
        $entity = new BatchStatus();
        $form = $this->createCreateForm($entity);

        return $this->render('BatchStatus/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a BatchStatus entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\BatchStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('BatchStatus/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing BatchStatus entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\BatchStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('BatchStatus/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
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
        $form = $this->createForm(BatchStatusType::class, $entity, array(
            'action' => $this->generateUrl('batchstatus_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing BatchStatus entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\BatchStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find BatchStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('batchstatus_show', array('id' => $id)));
        }

        return $this->render('BatchStatus/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a BatchStatus entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\BatchStatus::class)->find($id);

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
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
