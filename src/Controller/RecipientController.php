<?php

namespace App\Controller;

use App\Entity\Recipient;
use App\Form\RecipientType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recipient controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class RecipientController extends AbstractController
{

    /**
     * Lists all Recipient entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Recipient::class)->findAll();

        return $this->render('Recipient/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Recipient entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Recipient();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('recipient_show', array('id' => $entity->getId())));
        }

        return $this->render('Recipient/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Recipient entity.
     *
     * @param Recipient $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Recipient $entity)
    {
        $form = $this->createForm(RecipientType::class, $entity, array(
            'action' => $this->generateUrl('recipient_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Recipient entity.
     *
     */
    public function new()
    {
        $entity = new Recipient();
        $form = $this->createCreateForm($entity);

        return $this->render('Recipient/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Recipient entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Recipient::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Recipient/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Recipient entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Recipient::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Recipient/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Recipient entity.
     *
     * @param Recipient $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Recipient $entity)
    {
        $form = $this->createForm(RecipientType::class, $entity, array(
            'action' => $this->generateUrl('recipient_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Editar'));

        return $form;
    }

    /**
     * Edits an existing Recipient entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Recipient::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('recipient_show', array('id' => $id)));
        }

        return $this->render('Recipient/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Recipient entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Recipient::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Recipient entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('recipient'));
    }

    /**
     * Creates a form to delete a Recipient entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('recipient_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Borrar'))
            ->getForm();
    }
}
