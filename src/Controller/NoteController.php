<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Note;
use App\Form\NoteType;

/**
 * Note controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class NoteController extends AbstractAppController
{

    /**
     * Lists all Note entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\Note::class)->findAll();

        return $this->render('Note/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Note entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new Note();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('note_show', array('id' => $entity->getId())));
        }

        return $this->render('Note/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Note entity.
     *
     * @param Note $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Note $entity)
    {
        $form = $this->createForm(NoteType::class, $entity, array(
            'action' => $this->generateUrl('note_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Note entity.
     *
     */
    public function new()
    {
        $entity = new Note();
        $form = $this->createCreateForm($entity);

        return $this->render('Note/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Note entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Note::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Note entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Note/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Note entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Note::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Note entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Note/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Note entity.
     *
     * @param Note $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Note $entity)
    {
        $form = $this->createForm(NoteType::class, $entity, array(
            'action' => $this->generateUrl('note_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Note entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\Note::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Note entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('note_show', array('id' => $id)));
        }

        return $this->render('Note/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Note entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\Note::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Note entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('note'));
    }

    /**
     * Creates a form to delete a Note entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('note_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
