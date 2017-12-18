<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\CulturalWorkType;
use Gallinas\AppBundle\Form\CulturalWorkTypeType;

/**
 * CulturalWorkType controller.
 *
 */
class CulturalWorkTypeController extends Controller
{

    /**
     * Lists all CulturalWorkType entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:CulturalWorkType')->findAll();

        return $this->render('AppBundle:CulturalWorkType:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new CulturalWorkType entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new CulturalWorkType();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('culturalworktype_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:CulturalWorkType:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CulturalWorkType entity.
     *
     * @param CulturalWorkType $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CulturalWorkType $entity)
    {
        $form = $this->createForm(new CulturalWorkTypeType(), $entity, array(
            'action' => $this->generateUrl('culturalworktype_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CulturalWorkType entity.
     *
     */
    public function newAction()
    {
        $entity = new CulturalWorkType();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:CulturalWorkType:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a CulturalWorkType entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CulturalWorkType')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWorkType entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CulturalWorkType:show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CulturalWorkType entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CulturalWorkType')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWorkType entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CulturalWorkType:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a CulturalWorkType entity.
    *
    * @param CulturalWorkType $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(CulturalWorkType $entity)
    {
        $form = $this->createForm(new CulturalWorkTypeType(), $entity, array(
            'action' => $this->generateUrl('culturalworktype_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing CulturalWorkType entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CulturalWorkType')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CulturalWorkType entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('culturalworktype_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:CulturalWorkType:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a CulturalWorkType entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:CulturalWorkType')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find CulturalWorkType entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('culturalworktype'));
    }

    /**
     * Creates a form to delete a CulturalWorkType entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('culturalworktype_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
