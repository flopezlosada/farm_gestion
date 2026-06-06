<?php

namespace App\Controller;

use App\Entity\Purchaser;
use App\Form\PurchaserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Purchaser controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class PurchaserController extends AbstractController
{

    /**
     * Lists all Purchaser entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Purchaser::class)->findAll();

        return $this->render('Purchaser/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Purchaser entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Purchaser();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('dashboard', array('id' => $entity->getId())));
        }

        return $this->render('Purchaser/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Purchaser entity.
     *
     * @param Purchaser $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Purchaser $entity)
    {
        $form = $this->createForm(PurchaserType::class, $entity, array(
            'action' => $this->generateUrl('purchaser_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Purchaser entity.
     *
     */
    public function new()
    {
        $entity = new Purchaser();
        $form = $this->createCreateForm($entity);

        return $this->render('Purchaser/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Purchaser entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Purchaser::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Purchaser/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Purchaser entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Purchaser::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Purchaser/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Purchaser entity.
     *
     * @param Purchaser $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Purchaser $entity)
    {
        $form = $this->createForm(PurchaserType::class, $entity, array(
            'action' => $this->generateUrl('purchaser_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Purchaser entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Purchaser::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('purchaser'));
        }

        return $this->render('Purchaser/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Purchaser entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Purchaser::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Purchaser entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('purchaser'));
    }

    /**
     * Creates a form to delete a Purchaser entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('purchaser_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
