<?php

namespace App\Controller;

use App\Entity\Harvest;
use App\Form\HarvestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Harvest controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class HarvestController extends AbstractController
{

    /**
     * Lists all Harvest entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Harvest::class)->findAll();

        return $this->render('Harvest/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Harvest entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Harvest();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setHarvestDate(new \DateTime($entity->getHarvestDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('harvest_show', array('id' => $entity->getId())));
        }

        return $this->render('Harvest/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Harvest entity.
     *
     * @param Harvest $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Harvest $entity)
    {
        $form = $this->createForm(HarvestType::class, $entity, array(
            'action' => $this->generateUrl('harvest_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Harvest entity.
     *
     */
    public function new()
    {
        $entity = new Harvest();
        $form = $this->createCreateForm($entity);

        return $this->render('Harvest/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Harvest entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Harvest::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Harvest entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Harvest/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Harvest entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Harvest::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Harvest entity.');
        }
        if ($entity->getHarvestDate()) {
            $entity->setHarvestDate($entity->getHarvestDate()->format('Y-m-d'));
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Harvest/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Harvest entity.
     *
     * @param Harvest $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Harvest $entity)
    {
        $form = $this->createForm(HarvestType::class, $entity, array(
            'action' => $this->generateUrl('harvest_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Harvest entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Harvest::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Harvest entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        if ($entity->getHarvestDate() instanceof \DateTimeInterface) {
            $entity->setHarvestDate($entity->getHarvestDate()->format('Y-m-d'));
        }
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setHarvestDate(new \DateTime($entity->getHarvestDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('harvest_show', array('id' => $id)));
        }

        return $this->render('Harvest/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Harvest entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Harvest::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Harvest entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('harvest'));
    }

    /**
     * Creates a form to delete a Harvest entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('harvest_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
