<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\SeedWork;
use Gallinas\AppBundle\Form\SeedWorkType;

/**
 * SeedWork controller.
 *
 */
class SeedWorkController extends Controller
{

    /**
     * Lists all SeedWork entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:SeedWork')->findAll();

        return $this->render('AppBundle:SeedWork:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new SeedWork entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new SeedWork();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setEstimatedDate(new \DateTime($entity->getEstimatedDate())) ;
            $entity->setRealDate(new \DateTime($entity->getRealDate())) ;
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        }

        return $this->render('AppBundle:SeedWork:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a SeedWork entity.
     *
     * @param SeedWork $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(SeedWork $entity)
    {
        $form = $this->createForm(new SeedWorkType(), $entity, array(
            'action' => $this->generateUrl('seedwork_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new SeedWork entity.
     *
     */
    public function newAction($crop_working_id)
    {
        $entity = new SeedWork();
        if ($crop_working_id)
        {
            $em = $this->getDoctrine()->getManager();
            $crop_working = $em->getRepository("AppBundle:CropWorking")->find($crop_working_id);
            $entity->setCropWorking($crop_working);
        }
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:SeedWork:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a SeedWork entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:SeedWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:SeedWork:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing SeedWork entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:SeedWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }
        $entity->setEstimatedDate($entity->getEstimatedDate()->format('Y-m-d')) ;
        $entity->setRealDate($entity->getRealDate()->format('Y-m-d')) ;

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:SeedWork:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a SeedWork entity.
    *
    * @param SeedWork $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(SeedWork $entity)
    {
        $form = $this->createForm(new SeedWorkType(), $entity, array(
            'action' => $this->generateUrl('seedwork_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing SeedWork entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:SeedWork')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        $entity->setEstimatedDate($entity->getEstimatedDate()->format('Y-m-d')) ;
        $entity->setRealDate($entity->getRealDate()->format('Y-m-d')) ;

        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {

            $entity->setEstimatedDate(new \DateTime($entity->getEstimatedDate())) ;
            $entity->setRealDate(new \DateTime($entity->getRealDate())) ;
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        }

        return $this->render('AppBundle:SeedWork:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a SeedWork entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:SeedWork')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find SeedWork entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('cropworking'));
    }

    /**
     * Creates a form to delete a SeedWork entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('seedwork_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
