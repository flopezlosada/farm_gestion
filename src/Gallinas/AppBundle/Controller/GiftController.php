<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Gift;
use Gallinas\AppBundle\Form\GiftType;

/**
 * Gift controller.
 *
 */
class GiftController extends Controller
{

    /**
     * Lists all Gift entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Gift')->findAll();

        return $this->render('AppBundle:Gift:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Gift entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Gift();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getGiftDate())));
            $entity->setGiftDate(new \DateTime($entity->getGiftDate()));
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Gift:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Gift entity.
     *
     * @param Gift $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Gift $entity)
    {
        $form = $this->createForm(new GiftType(), $entity, array(
            'action' => $this->generateUrl('gift_create'),
            'method' => 'POST',
            'em' => $this->getDoctrine()->getManager()
        ));

        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Gift entity.
     *
     */
    public function newAction()
    {
        $entity = new Gift();
        $entity->setUser($this->getUser());
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Gift:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Gift entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Gift')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Gift:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Gift entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Gift')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }

        $entity->setGiftDate($entity->getGiftDate()->format('Y-m-d')) ;
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Gift:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Gift entity.
    *
    * @param Gift $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Gift $entity)
    {
        $form = $this->createForm(new GiftType(), $entity, array(
            'action' => $this->generateUrl('gift_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Actualizar'));

        return $form;
    }
    /**
     * Edits an existing Gift entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Gift')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Gift entity.');
        }
        $entity->setGiftDate($entity->getGiftDate()->format('Y-m-d')) ;
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $entity->setTotalPrice($entity->getSinglePrice() * $entity->getAmount());
            $entity->setGiftDate(new \DateTime($entity->getGiftDate())) ;
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Gift:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Gift entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Gift')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Gift entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('gift'));
    }

    /**
     * Creates a form to delete a Gift entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('gift_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
