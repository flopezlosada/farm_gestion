<?php

namespace App\Controller;

use App\Entity\Movement;
use App\Form\MovementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Movement controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class MovementController extends AbstractController
{

    /**
     * Lists all Movement entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Movement::class)->findAll();

        return $this->render('Movement/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Movement entity.
     *
     */
    public function create($batch_id, Request $request, EntityManagerInterface $em)
    {
        $entity = new Movement();
        $batch = $em->getRepository(\App\Entity\Batch::class)->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setDate(new \DateTime($entity->getDate()));
            $entity->setBatch($batch);
            $em->persist($entity);
            $last_movement = $em->getRepository(\App\Entity\Movement::class)->findLastMovement($batch_id);
            if (count($last_movement) > 0) {
                $interval = date_diff($last_movement[0]->getDate(), $entity->getDate(), false);
                $last_movement[0]->setAmount($interval->format('%R%a'));
                $em->persist($last_movement[0]);
            }

            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $batch_id)));
        }

        return $this->render('Movement/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'batch' => $batch,
        ));
    }

    /**
     * Creates a form to create a Movement entity.
     *
     * @param Movement $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Movement $entity, $batch)
    {
        $form = $this->createForm(MovementType::class, $entity, array(
            'action' => $this->generateUrl('movement_create', array('batch_id' => $batch->getId())),
            'method' => 'POST',
            'batch'  => $batch
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Movement entity.
     *
     */
    public function new($batch_id, EntityManagerInterface $em)
    {
        $entity = new Movement();
        $batch = $em->getRepository(\App\Entity\Batch::class)->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);


        return $this->render('Movement/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'batch' => $batch,
        ));
    }

    /**
     * Finds and displays a Movement entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Movement::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Movement/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Movement entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Movement::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Movement/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Movement entity.
     *
     * @param Movement $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Movement $entity)
    {
        $form = $this->createForm(MovementType::class, $entity, array(
            'action' => $this->generateUrl('movement_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Movement entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Movement::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Movement entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('movement_show', array('id' => $id)));
        }

        return $this->render('Movement/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Movement entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Movement::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Movement entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('movement'));
    }

    /**
     * Creates a form to delete a Movement entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('movement_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
