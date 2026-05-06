<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use App\Entity\CropWorking;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use App\Entity\SeedWork;
use App\Form\SeedWorkType;

/**
 * SeedWork controller.
 *
 */
class SeedWorkController extends AbstractController
{

    /**
     * Lists all SeedWork entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository(\App\Entity\SeedWork::class)->findAll();

        return $this->render('SeedWork/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new SeedWork entity.
     *
     */
    public function create($crop_working_id, Request $request)
    {
        $entity = new SeedWork();
        $em = $this->getDoctrine()->getManager();
        $crop_working = $em->getRepository(\App\Entity\CropWorking::class)->find($crop_working_id);

        $form = $this->createCreateForm($entity, $crop_working);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $entity->setCropWorking($crop_working);
            $entity->setEstimatedDate(new \DateTime($entity->getEstimatedDate()));
            $entity->setRealDate(new \DateTime($entity->getRealDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        }

        return $this->render('SeedWork/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a SeedWork entity.
     *
     * @param SeedWork $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(SeedWork $entity, CropWorking $crop_working)
    {
        $form = $this->createForm(SeedWorkType::class, $entity, array(
            'action' => $this->generateUrl('seedwork_create', array('crop_working_id' => $crop_working->getId())),
            'method' => 'POST',
            'crop_working'=>$crop_working
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new SeedWork entity.
     *
     */
    public function new($crop_working_id)
    {
        $entity = new SeedWork();
        if ($crop_working_id) {
            $em = $this->getDoctrine()->getManager();
            $crop_working = $em->getRepository(\App\Entity\CropWorking::class)->find($crop_working_id);
            $entity->setCropWorking($crop_working);
        }
        $form = $this->createCreateForm($entity, $crop_working);

        return $this->render('SeedWork/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'crop_working' => $crop_working
        ));
    }

    /**
     * Finds and displays a SeedWork entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\SeedWork::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }

        return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        // $deleteForm = $this->createDeleteForm($id);

        /*return $this->render('SeedWork/show.html.twig', array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        ));*/
    }

    /**
     * Displays a form to edit an existing SeedWork entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\SeedWork::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }
        $entity->setEstimatedDate($entity->getEstimatedDate()->format('Y-m-d'));
        $entity->setRealDate($entity->getRealDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity, $entity->getCropWorking());
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('SeedWork/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
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
    private function createEditForm(SeedWork $entity, $crop_working)
    {
        $form = $this->createForm(SeedWorkType::class, $entity, array(
            'action' => $this->generateUrl('seedwork_update', array('id' => $entity->getId())),
            'method' => 'PUT',
            'crop_working'=>$crop_working
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing SeedWork entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository(\App\Entity\SeedWork::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find SeedWork entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        $entity->setEstimatedDate($entity->getEstimatedDate()->format('Y-m-d'));
        $entity->setRealDate($entity->getRealDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity, $entity->getCropWorking());
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {

            $entity->setEstimatedDate(new \DateTime($entity->getEstimatedDate()));
            $entity->setRealDate(new \DateTime($entity->getRealDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        }

        return $this->render('SeedWork/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a SeedWork entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository(\App\Entity\SeedWork::class)->find($id);

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
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
