<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Collect;
use App\Form\CollectType;

/**
 * Collect controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class CollectController extends AbstractAppController
{

    /**
     * Lists all Collect entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Collect::class)->findAll();

        return $this->render('Collect/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Collect entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Collect();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getCollectDate())));
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Collect/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Collect entity.
     *
     * @param Collect $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Collect $entity)
    {
        $form = $this->createForm(CollectType::class, $entity, array(
            'action' => $this->generateUrl('collect_create'),
            'method' => 'POST',
            'em' => $this->getDoctrine()->getManager()
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Collect entity.
     *
     */
    public function new()
    {
        $entity = new Collect();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);

        return $this->render('Collect/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Collect entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Collect::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Collect/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Collect entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Collect::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Collect/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Collect entity.
     *
     * @param Collect $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Collect $entity)
    {
        $form = $this->createForm(CollectType::class, $entity, array(
            'action' => $this->generateUrl('collect_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Actualizar'));

        return $form;
    }

    /**
     * Edits an existing Collect entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Collect::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Collect/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Collect entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Collect::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Collect entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('calendar'));
    }

    /**
     * Creates a form to delete a Collect entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('collect_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Borrar'))
            ->getForm();
    }

}
