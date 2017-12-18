<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Crop;
use Gallinas\AppBundle\Form\CropType;

/**
 * Crop controller.
 *
 */
class CropController extends Controller
{

    /**
     * Lists all Crop entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Crop')->findAll();
        $year=date('Y');
        foreach ($entities as $entity)
        {
            $entity->setTotalProduction($em->getRepository('AppBundle:Production')->findTotalProduction($entity, $year));
        }



        return $this->render('AppBundle:Crop:index.html.twig', array(
            'entities' => $entities,
            'year'=> $year
        ));
    }

    /**
     * Creates a new Crop entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Crop();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity->setIsInProduction(0);
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('crop_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Crop:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Crop entity.
     *
     * @param Crop $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Crop $entity)
    {
        $form = $this->createForm(new CropType(), $entity, array(
            'action' => $this->generateUrl('crop_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Crop entity.
     *
     */
    public function newAction()
    {
        $entity = new Crop();
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Crop:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Crop entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Crop')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }



        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Crop:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Crop entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Crop')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Crop:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Crop entity.
     *
     * @param Crop $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Crop $entity)
    {
        $form = $this->createForm(new CropType(), $entity, array(
            'action' => $this->generateUrl('crop_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Crop entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Crop')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $em->flush();

            return $this->redirect($this->generateUrl('crop_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Crop:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Crop entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Crop')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Crop entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('crop'));
    }

    /**
     * Creates a form to delete a Crop entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('crop_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }
}
