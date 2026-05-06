<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;

use App\Entity\FowlDestination;
use App\Form\FowlDestinationType;

/**
 * FowlDestination controller.
 *
 */
class FowlDestinationController extends AbstractAppController
{

    /**
     * Lists all FowlDestination entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\FowlDestination::class)->findAll();

        return $this->render('FowlDestination/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new FowlDestination entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new FowlDestination();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('fowldestination_show', array('id' => $entity->getId())));
        }

        return $this->render('FowlDestination/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a FowlDestination entity.
     *
     * @param FowlDestination $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(FowlDestination $entity)
    {
        $form = $this->createForm(FowlDestinationType::class, $entity, array(
            'action' => $this->generateUrl('fowldestination_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new FowlDestination entity.
     *
     */
    public function new()
    {
        $entity = new FowlDestination();
        $form = $this->createCreateForm($entity);

        return $this->render('FowlDestination/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a FowlDestination entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\FowlDestination::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlDestination entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('FowlDestination/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing FowlDestination entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\FowlDestination::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlDestination entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('FowlDestination/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a FowlDestination entity.
     *
     * @param FowlDestination $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(FowlDestination $entity)
    {
        $form = $this->createForm(FowlDestinationType::class, $entity, array(
            'action' => $this->generateUrl('fowldestination_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing FowlDestination entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\FowlDestination::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlDestination entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('fowldestination_edit', array('id' => $id)));
        }

        return $this->render('FowlDestination/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a FowlDestination entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\FowlDestination::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find FowlDestination entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('fowldestination'));
    }

    /**
     * Creates a form to delete a FowlDestination entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('fowldestination_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
