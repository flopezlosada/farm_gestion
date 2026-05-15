<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\HttpFoundation\Request;
use App\Controller\AbstractAppController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\CompostCollection;
use App\Form\CompostCollectionType;

/**
 * CompostCollection controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class CompostCollectionController extends AbstractAppController
{

    /**
     * Lists all CompostCollection entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $colletion_points = $em->getRepository(\App\Entity\CompostCollectionPoint::class)->findAll();

        $current_year = date("Y");
        foreach ($colletion_points as $point) {
            $last_collection = $em->getRepository(\App\Entity\CompostCollection::class)->findLastByPoint($point);
            $point->setLastCompostCollection($last_collection);

            $year_collections = array();
            for ($i = $current_year; $i >= 2017; $i--) {
                $year_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findPointCollectionYear($i, $point);
            }
            $point->setYearCollections($year_collections);

        }


        return $this->render('CompostCollection/index.html.twig', array(
            'colletion_points' => $colletion_points,
        ));
    }

    public function resume()
    {
        $em = $this->getDoctrine()->getManager();
        $year_collections = array(); //valores por año
        $year_week_collections = array(); // valores por semana
        $year_month_collections = array(); // valores por mes
        $current_year = date("Y");
        for ($i = 2017; $i <= $current_year; $i++) {
            $year_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findTotalAmountCollection($i);
            $year_month_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findAmountCollectionByMonth($i);
            $year_week_collections[$i] = $em->getRepository(\App\Entity\CompostCollection::class)->findAmountCollectionByWeek($i);
        }


        return $this->render('CompostCollection/resume.html.twig', array(
            'year_collections' => $year_collections,
            'year_month_collections' => $year_month_collections,
            'year_week_collections' => $year_week_collections,
        ));
    }


    /**
     * Creates a new CompostCollection entity.
     *
     */
    public function create(Request $request)
    {
        $entity = new CompostCollection();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getCollectDate())));
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $entity->setCompostPile($em->getRepository(\App\Entity\CompostPile::class)->findActivePile());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollectionpoint_show', array('id' => $entity->getCompostCollectionPoint()->getId())));
        }

        return $this->render('CompostCollection/new.html.twig', array(
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
        $form = $this->createForm(CompostCollectionType::class, $entity, array(
            'action' => $this->generateUrl('compostcollection_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new CompostCollection entity.
     *
     */
    public function new()
    {
        $entity = new CompostCollection();
        $form = $this->createCreateForm($entity);

        return $this->render('CompostCollection/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }


    /**
     * Finds and displays a CompostCollection entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostCollection::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CompostCollection/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing CompostCollection entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostCollection::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }
        if ($entity->getCollectDate() instanceof \DateTimeInterface) {
            $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('CompostCollection/edit.html.twig', array(
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
        $form = $this->createForm(CompostCollectionType::class, $entity, array(
            'action' => $this->generateUrl('compostcollection_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CompostCollection entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\CompostCollection::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find CompostCollection entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        if ($entity->getCollectDate() instanceof \DateTimeInterface) {
            $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));
        }
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('compostcollection_show', array('id' => $id)));
        }

        return $this->render('CompostCollection/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CompostCollection entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\CompostCollection::class)->find($id);

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
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
