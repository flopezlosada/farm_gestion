<?php

namespace App\Controller;

use App\Entity\Sale;
use App\Form\SaleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sale controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class SaleController extends AbstractController
{

    /**
     * Lists all Sale entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Sale::class)->findAll();

        return $this->render('Sale/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Sale entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Sale();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity, $em);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setWeek(date('W', strtotime($entity->getSaleDate())));
            $entity->setSaleDate(new \DateTime($entity->getSaleDate()));
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Sale/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Sale entity.
     *
     * @param Sale $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Sale $entity, EntityManagerInterface $em)
    {
        $form = $this->createForm(SaleType::class, $entity, array(
            'action' => $this->generateUrl('sale_create'),
            'method' => 'POST',
            'em' => $em
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Sale entity.
     *
     */
    public function new(EntityManagerInterface $em)
    {
        $entity = new Sale();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity, $em);

        return $this->render('Sale/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Sale entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Sale::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Sale entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Sale/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Sale entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Sale::class)->find($id);
        $entity->setUser($this->getUser());
        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Sale entity.');
        }
        $entity->setSaleDate($entity->getSaleDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Sale/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Sale entity.
     *
     * @param Sale $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Sale $entity)
    {
        $form = $this->createForm(SaleType::class, $entity, array(
            'action' => $this->generateUrl('sale_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Actualizar'));

        return $form;
    }

    /**
     * Edits an existing Sale entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Sale::class)->find($id);
        $entity->setUser($this->getUser());

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Sale entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $entity->setSaleDate($entity->getSaleDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $entity->setSaleDate(new \DateTime($entity->getSaleDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('sale_show', array('id' => $id)));
        }

        return $this->render('Sale/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Sale entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Sale::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Sale entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('sale'));
    }

    /**
     * Creates a form to delete a Sale entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('sale_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Borrar'))
            ->getForm();
    }

    public function pay($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Sale::class)->find($id);
        $entity->setPaid(1);
        $em->persist($entity);
        $em->flush();

        return $this->redirect($this->generateUrl('sale_show', array('id' => $id)));
    }
}
