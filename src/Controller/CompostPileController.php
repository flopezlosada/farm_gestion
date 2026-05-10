<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;

use App\Entity\CompostPile;
use App\Form\CompostPileType;

/**
 * CompostPile controller.
 *
 */
class CompostPileController extends AbstractAppController
{

    /**
     * Lists all CompostPile entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $compost_piles = $em->getRepository(\App\Entity\CompostPile::class)->findAll();

        return $this->render('CompostPile/index.html.twig', array(
            'compost_piles' => $compost_piles,
        ));


    }

    /**
     * Creates a new CompostPile entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new CompostPile();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostpile_show', array('id' => $entity->getId())));
        }

        return $this->render('CompostPile/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CompostPile entity.
     *
     * @param CompostPile $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CompostPile $entity)
    {
        $form = $this->createForm(CompostPileType::class, $entity, array(
            'action' => $this->generateUrl('compostpile_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostPile entity.
     *
     */
    public function new()
    {
        $em = $this->getDoctrine()->getManager();
        $last_pile = $em->getRepository(\App\Entity\CompostPile::class)->findActivePile();

        if ($last_pile) {
            $last_pile->setEndDate(new \DateTime());
            $em->persist($last_pile);
        }

        $entity = new CompostPile();
        $entity->setStartDate(new \DateTime());

        $em->persist($entity);

        $em->flush();

        return $this->redirect($this->generateUrl('compostpile'));
    }

    /**
     * Finds and displays a CompostPile entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostPile::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CompostPile/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CompostPile entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostPile::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CompostPile/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a CompostPile entity.
     *
     * @param CompostPile $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(CompostPile $entity)
    {
        $form = $this->createForm(CompostPileType::class, $entity, array(
            'action' => $this->generateUrl('compostpile_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CompostPile entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostPile::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('compostpile_edit', array('id' => $id)));
        }

        return $this->render('CompostPile/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CompostPile entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\CompostPile::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find CompostPile entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('compostpile'));
    }

    /**
     * Creates a form to delete a CompostPile entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('compostpile_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
