<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\CompostCollection;
use Gallinas\AppBundle\Form\CompostCollectionType;

/**
 * CompostCollection controller.
 *
 */
class CompostCollectionController extends Controller
{

    /**
     * Lists all CompostCollection entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $colletion_points = $em->getRepository('AppBundle:CompostCollectionPoint')->findAll();

        $current_year = date("Y");
        foreach ($colletion_points as $point) {
            $last_collection = $em->getRepository("AppBundle:CompostCollection")->findLastByPoint($point);
            $point->setLastCompostCollection($last_collection);

            $year_collections = array();
            for ($i = $current_year; $i >= 2017; $i--) {
                $year_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findPointCollectionYear($i, $point);
            }
            $point->setYearCollections($year_collections);

        }


        return $this->render('AppBundle:CompostCollection:index.html.twig', array(
            'colletion_points' => $colletion_points,
        ));
    }

    public function resumeAction()
    {
        $em = $this->getDoctrine()->getManager();
        $year_collections= array(); //valores por año
        $year_month_collections= array(); // valores por mes
        $current_year = date("Y");
        for ($i = 2017; $i <= $current_year; $i++) {
            $year_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findTotalAmountCollection($i);
            $year_month_collections[$i] = $em->getRepository("AppBundle:CompostCollection")->findAmountCollectionByMonth($i);
        }


        return $this->render('AppBundle:CompostCollection:resume.html.twig', array(
            'year_collections' => $year_collections,
            'year_month_collections' => $year_month_collections,
        ));
    }


    /**
     * Creates a new CompostCollection entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new CompostCollection();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getCollectDate())));
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollection_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:CompostCollection:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CompostCollection entity.
     *
     * @param CompostCollection $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CompostCollection $entity)
    {
        $form = $this->createForm(new CompostCollectionType(), $entity, array(
            'action' => $this->generateUrl('compostcollection_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostCollection entity.
     *
     */
    public function newAction()
    {
        $entity = new CompostCollection();
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:CompostCollection:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }


    /**
     * Finds and displays a CompostCollection entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostCollection')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CompostCollection:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CompostCollection entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostCollection')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CompostCollection:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a CompostCollection entity.
     *
     * @param CompostCollection $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(CompostCollection $entity)
    {
        $form = $this->createForm(new CompostCollectionType(), $entity, array(
            'action' => $this->generateUrl('compostcollection_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CompostCollection entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CompostCollection')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollection_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:CompostCollection:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CompostCollection entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:CompostCollection')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find CompostCollection entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('compostcollection'));
    }

    /**
     * Creates a form to delete a CompostCollection entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('compostcollection_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }
}
