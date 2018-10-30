<?php

namespace Gallinas\AppBundle\Controller;

use Doctrine\ORM\EntityRepository;
use Gallinas\AppBundle\Entity\Sector;
use Gallinas\AppBundle\Form\CropWorkingEditType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\CropWorking;
use Gallinas\AppBundle\Form\CropWorkingType;

/**
 * CropWorking controller.
 *
 */
class CropWorkingController extends Controller
{

    /**
     * Lists all CropWorking entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:CropWorking')->findActive();

        foreach ($entities as $entity)
        {
            $entity->setTotalProduction($em->getRepository('AppBundle:Production')->findTotalCropProduction($entity));
        }


        return $this->render('AppBundle:CropWorking:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new CropWorking entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new CropWorking();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);

            $crop = $entity->getCrop();
            $crop->setIsInProduction(1);
            $em->persist($crop);
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:CropWorking:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a CropWorking entity.
     *
     * @param CropWorking $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(CropWorking $entity)
    {
        $form = $this->createForm(new CropWorkingType(), $entity, array(
            'action' => $this->generateUrl('cropworking_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new CropWorking entity.
     *
     */
    public function newAction($crop_id = null)
    {
        $entity = new CropWorking();
        if ($crop_id)
        {
            $em = $this->getDoctrine()->getManager();
            $crop = $em->getRepository("AppBundle:Crop")->find($crop_id);
            $entity->setCrop($crop);
        }

        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:CropWorking:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a CropWorking entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CropWorking')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find CropWorking entity.');
        }
        $total_production = $em->getRepository('AppBundle:Production')->findTotalCropProduction($entity);
        $year = date('Y');
        $productions = $em->getRepository("AppBundle:Production")->findBy(array("crop_working" => $entity->getId()), array("production_date" => "ASC"));
        $first_seed_work = $em->getRepository("AppBundle:SeedWork")->findFirstSeedWork($entity);

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CropWorking:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
            'year' => $year,
            'total_production' => $total_production,
            'productions' => $productions,
            "first_seed_work" => $first_seed_work

        ));
    }

    /**
     * Displays a form to edit an existing CropWorking entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CropWorking')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find CropWorking entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:CropWorking:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    public function finishAction($id,$finish)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CropWorking')->find($id);

        if ($finish)
        {
            $entity->setFinishDate(new \DateTime('now'));
            $entity->getCrop()->setIsInProduction(0);
        }
        else {
            $entity->setFinishDate(null);
            $entity->getCrop()->setIsInProduction(1);
        }

        $entity->setFinish($finish);

        $em->persist($entity);
        $em->flush();

        return $this->redirect($this->generateUrl('cropworking_show', array('id' => $id)));
    }

    /**
     * Creates a form to edit a CropWorking entity.
     *
     * @param CropWorking $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(CropWorking $entity)
    {
        $form = $this->createForm(new CropWorkingEditType(), $entity, array(
            'action' => $this->generateUrl('cropworking_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing CropWorking entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:CropWorking')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find CropWorking entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $id)));
        }

        return $this->render('AppBundle:CropWorking:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a CropWorking entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:CropWorking')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find CropWorking entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('cropworking'));
    }


    /**
     * Creates a form to delete a CropWorking entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('cropworking_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }

    public function addSectorAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $crop_working = $em->getRepository('AppBundle:CropWorking')->find($id);

        if (!$crop_working)
        {
            throw $this->createNotFoundException('Unable to find CropWorking entity.');
        }

        $form = $this->createFormBuilder($crop_working)
            ->add('zone', 'entity', array('class' => 'Gallinas\AppBundle\Entity\Zone', 'required' => true, 'empty_value' => "Selecciona zona", 'label' => "Zonas de cultivo",
                'query_builder' => function (EntityRepository $er)
                {
                    return $er->createQueryBuilder('u')
                        ->orderBy('u.name', 'ASC');
                }))
            ->add('sector', 'entity', array("label" => "Sector", 'class' => 'Gallinas\AppBundle\Entity\Sector', 'required' => true))
            ->add('submit', 'submit', array('label' => 'Añadir Sector'))
            ->getForm();

        return $this->render('AppBundle:CropWorking:add_sector.html.twig', array(
            'form' => $form->createView(),
            'crop_working' => $crop_working
        ));
    }

    public function addedSectorAction($id, Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $crop_working = $em->getRepository('AppBundle:CropWorking')->find($id);

        $values = $request->get('form');
        $sector = $em->getRepository('AppBundle:Sector')->find($values['sector']);

        $crop_working->addSector($sector);
        $sector->addCropWorking($crop_working);
        $em->persist($crop_working);
        $em->persist($sector);
        $em->flush();

        return $this->redirect($this->generateUrl('cropworking_show', array('id' => $id)));

    }

    public function deleteSectorAction($sector_id, $crop_working_id)
    {
        $em = $this->getDoctrine()->getManager();

        $em->getRepository('AppBundle:Sector')->deleteSector($sector_id, $crop_working_id);

        return $this->redirect($this->generateUrl('cropworking_show', array('id' => $crop_working_id)));
    }
}
