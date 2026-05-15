<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Provider;
use App\Form\ProviderType;

/**
 * Provider controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class ProviderController extends AbstractAppController
{

    /**
     * Lists all Provider entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Provider::class)->findAll();

        return $this->render('Provider/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Provider entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Provider();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('provider_show', array('id' => $entity->getId())));
        }

        return $this->render('Provider/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Provider entity.
     *
     * @param Provider $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Provider $entity)
    {
        $form = $this->createForm(ProviderType::class, $entity, array(
            'action' => $this->generateUrl('provider_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Provider entity.
     *
     */
    public function new()
    {
        $entity = new Provider();
        $form = $this->createCreateForm($entity);

        return $this->render('Provider/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Provider entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Provider::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Provider entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Provider/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Provider entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Provider::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Provider entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Provider/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Provider entity.
     *
     * @param Provider $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Provider $entity)
    {
        $form = $this->createForm(ProviderType::class, $entity, array(
            'action' => $this->generateUrl('provider_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Provider entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Provider::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Provider entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('provider_show', array('id' => $id)));
        }

        return $this->render('Provider/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Provider entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Provider::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Provider entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('provider'));
    }

    /**
     * Creates a form to delete a Provider entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('provider_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
