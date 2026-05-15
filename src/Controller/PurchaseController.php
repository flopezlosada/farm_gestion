<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Purchase;
use App\Form\PurchaseType;

/**
 * Purchase controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class PurchaseController extends AbstractAppController
{

    /**
     * Lists all Purchase entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Purchase::class)->findAll();

        return $this->render('Purchase/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Purchase entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Purchase();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Purchase/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Purchase entity.
     *
     * @param Purchase $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Purchase $entity)
    {
        $form = $this->createForm(PurchaseType::class, $entity, array(
            'action' => $this->generateUrl('purchase_create'),
            'method' => 'POST',
            'em' => $this->getDoctrine()->getManager()
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Purchase entity.
     *
     */
    public function new()
    {
        $entity = new Purchase();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);

        return $this->render('Purchase/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Purchase entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Purchase::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchase entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Purchase/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Purchase entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Purchase::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchase entity.');
        }

        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Purchase/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Purchase entity.
     *
     * @param Purchase $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Purchase $entity)
    {
        $form = $this->createForm(PurchaseType::class, $entity, array(
            'action' => $this->generateUrl('purchase_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Purchase entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Purchase::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchase entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('purchase_show', array('id' => $id)));
        }

        return $this->render('Purchase/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Purchase entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Purchase::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Purchase entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('purchase'));
    }

    /**
     * Creates a form to delete a Purchase entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('purchase_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
