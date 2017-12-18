<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Lay;
use Gallinas\AppBundle\Form\LayType;

/**
 * Lay controller.
 *
 */
class LayController extends Controller
{

    /**
     * Lists all Lay entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Lay')->findAll();

        return $this->render('AppBundle:Lay:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new Lay entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Lay();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {

            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W',strtotime($entity->getLayDate())));
            $entity->setLayDate(new \DateTime($entity->getLayDate())) ;
            $em->persist($entity);

            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Lay:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Lay entity.
     *
     * @param Lay $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Lay $entity)
    {
        $form = $this->createForm(new LayType(), $entity, array(
            'action' => $this->generateUrl('lay_create'),
            'method' => 'POST',
            'em'=>$this->getDoctrine()->getManager()
        ));

        $form->add('submit', 'submit', array('label' => 'Crear'));

        return $form;
    }

    /**
     * Displays a form to create a new Lay entity.
     *
     */
    public function newAction()
    {
        $entity = new Lay();
        $entity->setUser($this->getUser());
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:Lay:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Lay entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Lay')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Lay entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Lay:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Lay entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Lay')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Lay entity.');
        }
        $entity->setLayDate($entity->getLayDate()->format('Y-m-d')) ;

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Lay:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a Lay entity.
    *
    * @param Lay $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(Lay $entity)
    {
        $form = $this->createForm(new LayType(), $entity, array(
            'action' => $this->generateUrl('lay_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing Lay entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Lay')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Lay entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        $entity->setLayDate($entity->getLayDate()->format('Y-m-d')) ;
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $entity->setLayDate(new \DateTime($entity->getLayDate())) ;
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Lay:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a Lay entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Lay')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Lay entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('calendar'));
    }

    /**
     * Creates a form to delete a Lay entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('lay_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
