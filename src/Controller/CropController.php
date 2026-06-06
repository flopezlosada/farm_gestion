<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use App\Entity\Crop;
use App\Form\CropType;

/**
 * Crop controller.
 *
 */
#[IsGranted('ROLE_GESTION_GRANJA')]
class CropController extends AbstractController
{

    /**
     * Lists all Crop entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Crop::class)->findAll();
        $year = date('Y');
        foreach ($entities as $entity) {
            $entity->setTotalProduction($em->getRepository(\App\Entity\Production::class)->findTotalProduction($entity, $year));
        }


        return $this->render('Crop/index.html.twig', array(
            'entities' => $entities,
            'year' => $year
        ));
    }

    /**
     * Creates a new Crop entity.
     *
     */
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Crop();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setIsInProduction(0);
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('crop_show', array('id' => $entity->getId())));
        }

        return $this->render('Crop/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Crop entity.
     *
     * @param Crop $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Crop $entity)
    {
        $form = $this->createForm(CropType::class, $entity, array(
            'action' => $this->generateUrl('crop_create'),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Crop entity.
     *
     */
    public function new()
    {
        $entity = new Crop();
        $form = $this->createCreateForm($entity);

        return $this->render('Crop/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function resume(EntityManagerInterface $em)
    {

        /*$productions=$em->getRepository(\App\Entity\Production::class)->findAll();
        foreach ($productions as $production)
        {
            $production->setCrop($production->getCropWorking()->getCrop());
            $em->persist($production);
        }

        $em->flush();*/


        $years = array();
        for ($i = date('Y'); $i >= 2017; $i--) {
            $years[] = $i;
        }


        $crops = $em->getRepository(\App\Entity\Crop::class)->findAll();

        $crop_resume_production = array();
        $production = array();
        foreach ($crops as $crop) {
            $crop_resume_production = array($crop);
            foreach ($years as $year) {
                array_push($crop_resume_production, $em->getRepository(\App\Entity\Production::class)->findTotalProduction($crop, $year));
            }
            $production[] = $crop_resume_production;
        }

        //dump($production);


        return $this->render('Crop/resume.html.twig', array(
            "production" => $production,
            "years" => $years,
        ));
    }

    /**
     * Finds and displays a Crop entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Crop::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }


        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Crop/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Crop entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Crop::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Crop/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Crop entity.
     *
     * @param Crop $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Crop $entity)
    {
        $form = $this->createForm(CropType::class, $entity, array(
            'action' => $this->generateUrl('crop_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Crop entity.
     *
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Crop::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Crop entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('crop_show', array('id' => $id)));
        }

        return $this->render('Crop/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Crop entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Crop::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Crop entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('crop'));
    }

    /**
     * Creates a form to delete a Crop entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('crop_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
