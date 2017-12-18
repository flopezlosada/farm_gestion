<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Sector;
use Gallinas\AppBundle\Form\SectorType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * Sector controller.
 *
 */
class SectorController extends Controller
{

    /**
     * Lists all Sector entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Sector')->findAll();

        return $this->render('AppBundle:Sector:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Sector entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Sector();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('sector_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Sector:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Sector entity.
     *
     * @param Sector $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Sector $entity)
    {
        $form = $this->createForm(new SectorType(), $entity, array(
            'action' => $this->generateUrl('sector_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Sector entity.
     *
     */
    public function newAction()
    {
        $entity = new Sector();
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Sector:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Sector entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sector')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sector entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Sector:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Sector entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sector')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sector entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Sector:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Sector entity.
     *
     * @param Sector $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Sector $entity)
    {
        $form = $this->createForm(new SectorType(), $entity, array(
            'action' => $this->generateUrl('sector_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Sector entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sector')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sector entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $em->flush();

            return $this->redirect($this->generateUrl('sector_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Sector:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Sector entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Sector')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Sector entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('sector'));
    }

    /**
     * Creates a form to delete a Sector entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('sector_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }

    /**
     * @Route("/sectors", name="select_sector")
     * @Template()
     */

    public function sectorsAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $zone = $em->getRepository("AppBundle:Zone")->findById($request->get('zone_id'));
        $sectors = $em->getRepository('AppBundle:Sector')->findByZone($zone);


        return array(
            'sectors' => $sectors
        );
    }

}
