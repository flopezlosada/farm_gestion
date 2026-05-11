<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;

use App\Entity\Event;
use App\Form\EventType;

/**
 * Event controller.
 *
 */
class EventController extends AbstractAppController
{

    /**
     * Lists all Event entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Event::class)->findAll();

        return $this->render('Event/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Event entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Event();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setStartDate(new \DateTime($entity->getStartDate()));
            if ($entity->getEndDate()) {
                $entity->setEndDate(new \DateTime($entity->getEndDate()));
            }
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('Event/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Event entity.
     *
     * @param Event $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Event $entity)
    {
        $form = $this->createForm(EventType::class, $entity, array(
            'action' => $this->generateUrl('event_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Event entity.
     *
     */
    public function new()
    {
        $entity = new Event();
        $form = $this->createCreateForm($entity);

        return $this->render('Event/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Event entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Event::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Event entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Event/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Event entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Event::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Event entity.');
        }
        if ($entity->getStartDate() instanceof \DateTimeInterface) {
            $entity->setStartDate($entity->getStartDate()->format('Y-m-d'));
        }
        if ($entity->getEndDate() instanceof \DateTimeInterface) {
            $entity->setEndDate($entity->getEndDate()->format('Y-m-d'));
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Event/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Event entity.
     *
     * @param Event $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Event $entity)
    {
        $form = $this->createForm(EventType::class, $entity, array(
            'action' => $this->generateUrl('event_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Event entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Event::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Event entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        if ($entity->getStartDate() instanceof \DateTimeInterface) {
            $entity->setStartDate($entity->getStartDate()->format('Y-m-d'));
        }
        if ($entity->getEndDate() instanceof \DateTimeInterface) {
            $entity->setEndDate($entity->getEndDate()->format('Y-m-d'));
        }
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            if ($entity->getStartDate()) {
                $entity->setStartDate(new \DateTime($entity->getStartDate()));
            }
            if ($entity->getEndDate()) {
                $entity->setEndDate(new \DateTime($entity->getEndDate()));
            }
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }


        return $this->render('Event/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Event entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Event::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Event entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('event'));
    }

    /**
     * Creates a form to delete a Event entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('event_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Borrar'))
            ->getForm();
    }
}
