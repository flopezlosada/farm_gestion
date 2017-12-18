<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Purchaser;
use Gallinas\AppBundle\Form\PurchaserType;

/**
 * Purchaser controller.
 *
 */
class PurchaserController extends Controller
{

    /**
     * Lists all Purchaser entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Purchaser')->findAll();

        return $this->render('AppBundle:Purchaser:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Purchaser entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Purchaser();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('dashboard', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Purchaser:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Purchaser entity.
     *
     * @param Purchaser $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Purchaser $entity)
    {
        $form = $this->createForm(new PurchaserType(), $entity, array(
            'action' => $this->generateUrl('purchaser_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Purchaser entity.
     *
     */
    public function newAction()
    {
        $entity = new Purchaser();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Purchaser:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Purchaser entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Purchaser')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Purchaser:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Purchaser entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Purchaser')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Purchaser:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Purchaser entity.
    *
    * @param Purchaser $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Purchaser $entity)
    {
        $form = $this->createForm(new PurchaserType(), $entity, array(
            'action' => $this->generateUrl('purchaser_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Purchaser entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Purchaser')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Purchaser entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('purchaser'));
        }

        return $this->render('AppBundle:Purchaser:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Purchaser entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Purchaser')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Purchaser entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('purchaser'));
    }

    /**
     * Creates a form to delete a Purchaser entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('purchaser_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
