<?php

namespace App\Controller;

use App\Entity\Gift;
use App\Form\GiftType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gift controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class GiftController extends AbstractController
{

    /**
     * Lists all Gift entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Gift::class)->findAll();

        return $this->render('Gift/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Gift entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Gift();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity, $em);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setWeek(date('W', strtotime($entity->getGiftDate())));
            $entity->setGiftDate(new \DateTime($entity->getGiftDate()));
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Gift/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Gift entity.
     *
     * @param Gift $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Gift $entity, EntityManagerInterface $em)
    {
        $form = $this->createForm(GiftType::class, $entity, array(
            'action' => $this->generateUrl('gift_create'),
            'method' => 'POST',
            'em' => $em
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Gift entity.
     *
     */
    public function new(EntityManagerInterface $em)
    {
        $entity = new Gift();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity, $em);

        return $this->render('Gift/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Gift entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Gift::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Gift/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Gift entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Gift::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }

        $entity->setGiftDate($entity->getGiftDate()->format('Y-m-d'));
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Gift/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Gift entity.
     *
     * @param Gift $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Gift $entity)
    {
        $form = $this->createForm(GiftType::class, $entity, array(
            'action' => $this->generateUrl('gift_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Actualizar'));

        return $form;
    }

    /**
     * Edits an existing Gift entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Gift::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }
        $entity->setGiftDate($entity->getGiftDate()->format('Y-m-d'));
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $entity->setGiftDate(new \DateTime($entity->getGiftDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Gift/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Gift entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Gift::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Gift entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('gift'));
    }

    /**
     * Creates a form to delete a Gift entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('gift_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
