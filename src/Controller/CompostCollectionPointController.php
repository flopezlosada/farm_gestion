<?php

namespace App\Controller;

use App\Entity\CompostCollectionPoint;
use App\Form\CompostCollectionPointType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CompostCollectionPoint controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class CompostCollectionPointController extends AbstractController
{

    /**
     * Lists all CompostCollectionPoint entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->findAll();

        return $this->render('CompostCollectionPoint/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new CompostCollectionPoint entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new CompostCollectionPoint();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollectionpoint_show', array('id' => $entity->getId())));
        }

        return $this->render('CompostCollectionPoint/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
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
        $form = $this->createForm(CompostCollectionPointType::class, $entity, array(
            'action' => $this->generateUrl('compostcollectionpoint_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostCollectionPoint entity.
     *
     */
    public function new()
    {
        $entity = new CompostCollectionPoint();
        $form = $this->createCreateForm($entity);

        return $this->render('CompostCollectionPoint/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a CompostCollectionPoint entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $point = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->find($id);

        if (!$point) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }


        $year_collections = array(); //valores por año
        $year_month_collections = array(); // valores por mes
        $year_week_collections = array(); // valores por semana
        $current_year = date("Y");
        for ($i = 2017; $i <= $current_year; $i++) {
            $year_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findTotalAmountCollection($i, $point);
            $year_month_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findAmountCollectionByMonth($i, $point);
            $year_week_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findAmountCollectionByWeek($i, $point);
        }


        return $this->render('CompostCollectionPoint/show.html.twig', array(
            'point' => $point,
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
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CompostCollectionPoint/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
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
        $form = $this->createForm(CompostCollectionPointType::class, $entity, array(
            'action' => $this->generateUrl('compostcollectionpoint_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CompostCollectionPoint entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollectionPoint entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollectionpoint_show', array('id' => $id)));
        }

        return $this->render('CompostCollectionPoint/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CompostCollectionPoint entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->find($id);

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
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
