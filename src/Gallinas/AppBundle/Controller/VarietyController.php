<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Variety;
use Gallinas\AppBundle\Form\VarietyType;

/**
 * Variety controller.
 *
 */
class VarietyController extends Controller
{

    /**
     * Lists all Variety entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Variety')->findAll();

        return $this->render('AppBundle:Variety:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Variety entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Variety();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('variety_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Variety:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Variety entity.
     *
     * @param Variety $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Variety $entity)
    {
        $form = $this->createForm(new VarietyType(), $entity, array(
            'action' => $this->generateUrl('variety_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Variety entity.
     *
     */
    public function newAction()
    {
        $entity = new Variety();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Variety:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Variety entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Variety')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Variety entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Variety:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Variety entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Variety')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Variety entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Variety:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Variety entity.
    *
    * @param Variety $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Variety $entity)
    {
        $form = $this->createForm(new VarietyType(), $entity, array(
            'action' => $this->generateUrl('variety_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Variety entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Variety')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Variety entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('variety_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Variety:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Variety entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Variety')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Variety entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('variety'));
    }

    /**
     * Creates a form to delete a Variety entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('variety_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
