<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\CompostCollectionPoint;
use Gallinas\AppBundle\Form\CompostCollectionPointType;

/**
 * CompostCollectionPoint controller.
 *
 */
class CompostCollectionPointController extends Controller
{

    /**
     * Lists all CompostCollectionPoint entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:CompostCollectionPoint')->findAll();

        return $this->render('AppBundle:CompostCollectionPoint:index.html.twig', array(
            'entities' => $entities,
        ));
    }
    /**
     * Creates a new CompostCollectionPoint entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new CompostCollectionPoint();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollectionpoint_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:CompostCollectionPoint:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CompostCollectionPoint entity.
     *
     * @param CompostCollectionPoint $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CompostCollectionPoint $entity)
    {
        $form = $this->createForm(new CompostCollectionPointType(), $entity, array(
            'action' => $this->generateUrl('compostcollectionpoint_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostCollectionPoint entity.
     *
     */
    public function newAction()
    {
        $entity = new CompostCollectionPoint();
        $form   = $this->createCreateForm($entity);

        return $this->render('AppBundle:CompostCollectionPoint:new.html.twig', array(
            'entity' => $entity,
            'form'   => $form->createView(),
        ));
    }

    /**
     * Finds and displays a CompostCollectionPoint entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $point = $em->getRepository('AppBundle:CompostCollectionPoint')->find($id);

        if (!$point) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }


        $year_collections= array(); //valores por año
        $year_month_collections= array(); // valores por mes
        $year_week_collections= array(); // valores por semana
        $current_year = date("Y");
        for ($i = 2017; $i <= $current_year; $i++) {
            $year_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findTotalAmountCollection($i,$point);
            $year_month_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findAmountCollectionByMonth($i,$point);
            $year_week_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findAmountCollectionByWeek($i,$point);
        }


        return $this->render('AppBundle:CompostCollectionPoint:show.html.twig', array(
            'point'=>$point,
            'year_collections' => $year_collections,
            'year_month_collections' => $year_month_collections,
            'year_week_collections' => $year_week_collections,
        ));
        //$deleteForm = $this->createDeleteForm($id);


    }

    /**
     * Displays a form to edit an existing CompostCollectionPoint entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostCollectionPoint')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CompostCollectionPoint:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
    * Creates a form to edit a CompostCollectionPoint entity.
    *
    * @param CompostCollectionPoint $entity The entity
    *
    * @return \Symfony\Component\Form\Form The form
    */
    private function createEditForm(CompostCollectionPoint $entity)
    {
        $form = $this->createForm(new CompostCollectionPointType(), $entity, array(
            'action' => $this->generateUrl('compostcollectionpoint_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }
    /**
     * Edits an existing CompostCollectionPoint entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostCollectionPoint')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollectionpoint_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:CompostCollectionPoint:edit.html.twig', array(
            'entity'      => $entity,
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }
    /**
     * Deletes a CompostCollectionPoint entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:CompostCollectionPoint')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('compostcollectionpoint'));
    }

    /**
     * Creates a form to delete a CompostCollectionPoint entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('compostcollectionpoint_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm()
        ;
    }
}
