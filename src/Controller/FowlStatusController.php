<?php

namespace App\Controller;

use App\Entity\FowlStatus;
use App\Form\FowlStatusType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FowlStatus controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class FowlStatusController extends AbstractController
{

    /**
     * Lists all FowlStatus entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\FowlStatus::class)->findAll();

        return $this->render('FowlStatus/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new FowlStatus entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new FowlStatus();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('fowlstatus_show', array('id' => $entity->getId())));
        }

        return $this->render('FowlStatus/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a FowlStatus entity.
     *
     * @param FowlStatus $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(FowlStatus $entity)
    {
        $form = $this->createForm(FowlStatusType::class, $entity, array(
            'action' => $this->generateUrl('fowlstatus_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new FowlStatus entity.
     *
     */
    public function new()
    {
        $entity = new FowlStatus();
        $form = $this->createCreateForm($entity);

        return $this->render('FowlStatus/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a FowlStatus entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\FowlStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('FowlStatus/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing FowlStatus entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\FowlStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlStatus entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('FowlStatus/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a FowlStatus entity.
     *
     * @param FowlStatus $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(FowlStatus $entity)
    {
        $form = $this->createForm(FowlStatusType::class, $entity, array(
            'action' => $this->generateUrl('fowlstatus_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing FowlStatus entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\FowlStatus::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find FowlStatus entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('fowlstatus_show', array('id' => $id)));
        }

        return $this->render('FowlStatus/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a FowlStatus entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\FowlStatus::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find FowlStatus entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('fowlstatus'));
    }

    /**
     * Creates a form to delete a FowlStatus entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('fowlstatus_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
