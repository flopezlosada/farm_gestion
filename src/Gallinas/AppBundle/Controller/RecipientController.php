<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Recipient;
use Gallinas\AppBundle\Form\RecipientType;

/**
 * Recipient controller.
 *
 */
class RecipientController extends Controller
{

    /**
     * Lists all Recipient entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Recipient')->findAll();

        return $this->render('AppBundle:Recipient:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Recipient entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Recipient();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('recipient_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Recipient:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Recipient entity.
     *
     * @param Recipient $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Recipient $entity)
    {
        $form = $this->createForm(new RecipientType(), $entity, array(
            'action' => $this->generateUrl('recipient_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Recipient entity.
     *
     */
    public function newAction()
    {
        $entity = new Recipient();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Recipient:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Recipient entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Recipient')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Recipient:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Recipient entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Recipient')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Recipient:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Recipient entity.
    *
    * @param Recipient $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Recipient $entity)
    {
        $form = $this->createForm(new RecipientType(), $entity, array(
            'action' => $this->generateUrl('recipient_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Editar'));

        return $form;
    }
    /**
     * Edits an existing Recipient entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Recipient')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Recipient entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('recipient_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Recipient:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Recipient entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Recipient')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Recipient entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('recipient'));
    }

    /**
     * Creates a form to delete a Recipient entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('recipient_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Borrar'))
            ->getForm()
        ;
    }
}
