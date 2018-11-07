<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\CompostPile;
use Gallinas\AppBundle\Form\CompostPileType;

/**
 * CompostPile controller.
 *
 */
class CompostPileController extends Controller
{

    /**
     * Lists all CompostPile entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $compost_piles = $em->getRepository('AppBundle:CompostPile')->findAll();

        return $this->render('AppBundle:CompostPile:index.html.twig', array(
            'compost_piles' => $compost_piles,
        ));


    }

    /**
     * Creates a new CompostPile entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new CompostPile();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostpile_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:CompostPile:new.html.twig', array(
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
        $form = $this->createForm(new CompostPileType(), $entity, array(
            'action' => $this->generateUrl('compostpile_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostPile entity.
     *
     */
    public function newAction()
    {
        $em = $this->getDoctrine()->getManager();
        $last_pile = $em->getRepository("AppBundle:CompostPile")->findActivePile();

        if ($last_pile)
        {
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
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostPile')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CompostPile:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CompostPile entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostPile')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CompostPile:edit.html.twig', array(
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
        $form = $this->createForm(new CompostPileType(), $entity, array(
            'action' => $this->generateUrl('compostpile_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CompostPile entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostPile')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostPile entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('compostpile_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:CompostPile:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CompostPile entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:CompostPile')->find($id);

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
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }
}
