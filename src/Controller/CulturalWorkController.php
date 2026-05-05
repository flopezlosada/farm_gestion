<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use App\Entity\CulturalWork;
use App\Form\CulturalWorkType;

/**
 * CulturalWork controller.
 *
 */
class CulturalWorkController extends AbstractController
{

    /**
     * Lists all CulturalWork entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('App:CulturalWork')->findAll();

        return $this->render('CulturalWork/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new CulturalWork entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new CulturalWork();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setDate(new \DateTime($entity->getDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('culturalwork_show', array('id' => $entity->getId())));
        }

        return $this->render('CulturalWork/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CulturalWork entity.
     *
     * @param CulturalWork $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CulturalWork $entity)
    {
        $form = $this->createForm(CulturalWorkType::class, $entity, array(
            'action' => $this->generateUrl('culturalwork_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CulturalWork entity.
     *
     */
    public function new()
    {
        $entity = new CulturalWork();
        $form = $this->createCreateForm($entity);

        return $this->render('CulturalWork/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a CulturalWork entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:CulturalWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWork entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CulturalWork/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CulturalWork entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:CulturalWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWork entity.');
        }

        $entity->setDate($entity->getDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CulturalWork/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a CulturalWork entity.
     *
     * @param CulturalWork $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(CulturalWork $entity)
    {
        $form = $this->createForm(CulturalWorkType::class, $entity, array(
            'action' => $this->generateUrl('culturalwork_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CulturalWork entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:CulturalWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWork entity.');
        }
        $entity->setDate($entity->getDate()->format('Y-m-d'));
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $entity->setDate(new \DateTime($entity->getDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('culturalwork_show', array('id' => $id)));
        }

        return $this->render('CulturalWork/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CulturalWork entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('App:CulturalWork')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find CulturalWork entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('culturalwork'));
    }

    /**
     * Creates a form to delete a CulturalWork entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('culturalwork_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
