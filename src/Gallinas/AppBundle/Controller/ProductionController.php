<?php

namespace Gallinas\AppBundle\Controller;

use Gallinas\AppBundle\Entity\Basket;
use Proxies\__CG__\Gallinas\AppBundle\Entity\CropWorking;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Production;
use Gallinas\AppBundle\Form\ProductionType;

/**
 * Production controller.
 *
 */
class ProductionController extends Controller
{

    /**
     * Lists all Production entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Production')->findAll();
        /*$crops=$em->getRepository('AppBundle:Crop')->findAll();
        foreach ($crops as $crop)
        {
            $crop_working=new CropWorking();
            $crop_working->setCrop($crop);
            $crop_working->setFinish(0);
            $crop_working->setSurface(100);
            $crop_working->setName($crop->getName()." en producción");
            $crop_working->setPlantingPattern("50x50");
            $crop_working->setEstimatedProduction(300);

            $em->persist($crop_working);
        }

        $em->flush();*/

        return $this->render('AppBundle:Production:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Production entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Production();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getProductionDate())));
            $basket = $em->getRepository("AppBundle:Basket")->findBasketByWeekYear($entity->getProductionDate());
            if (!$basket) {
                $basket = new Basket();
                $monday = date('d F', strtotime(date('Y', strtotime($entity->getProductionDate())) . "W" . str_pad($entity->getWeek(), 2, "0", STR_PAD_LEFT)));
                //echo strtotime(date('Y', strtotime($entity->getProductionDate())) . "W" . str_pad($entity->getWeek(), 2, "0", STR_PAD_LEFT))."<br>";
                //echo date('Y', strtotime($entity->getProductionDate()));
                $friday = strtotime("+4 day", strtotime($monday));
                //echo $friday;
                $basket->setDate(new \DateTime(date("Y-m-d", $friday)));
                //echo $basket->getDate()->format(("Y-m-d"));
                $basket->setWeek($entity->getWeek());
                $em->persist($basket);
            }
            $entity->setProductionDate(new \DateTime($entity->getProductionDate()));
            $entity->setBasket($basket);
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('cropworking_show', array('id' => $entity->getCropWorking()->getId())));
        }

        return $this->render('AppBundle:Production:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Production entity.
     *
     * @param Production $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Production $entity)
    {
        $form = $this->createForm(new ProductionType(), $entity, array(
            'action' => $this->generateUrl('production_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Production entity.
     *
     */
    public function newAction($crop_working_id = null)
    {
        $entity = new Production();
        if ($crop_working_id) {
            $em = $this->getDoctrine()->getManager();
            $crop_working = $em->getRepository("AppBundle:CropWorking")->find($crop_working_id);
            $entity->setCropWorking($crop_working);
        }
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Production:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Production entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Production')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Production entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Production:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Production entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Production')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Production entity.');
        }
        $entity->setProductionDate($entity->getProductionDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Production:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Production entity.
     *
     * @param Production $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Production $entity)
    {
        $form = $this->createForm(new ProductionType(), $entity, array(
            'action' => $this->generateUrl('production_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Production entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Production')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Production entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        $entity->setProductionDate($entity->getProductionDate()->format('Y-m-d'));
        $entity->setWeek(date('W', strtotime($entity->getProductionDate())));
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            $entity->setProductionDate(new \DateTime($entity->getProductionDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('production_show_basket_detail', array('id' => $entity->getBasket()->getId())));
        }

        return $this->render('AppBundle:Production:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Production entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Production')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Production entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('production'));
    }

    /**
     * Creates a form to delete a Production entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('production_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }

    public function basketAction($year)
    {
        if (!$year) {
            $year = date('Y');
        }
        $em = $this->getDoctrine()->getManager();
        $weeks = $em->getRepository("AppBundle:Production")->findWeeks($year);//semanas en las que hay producción declarada. Equivale a semanas en las que se entrega cesta.


        foreach ($weeks as $week) {
            $baskets[$week["week"]] = $em->getRepository("AppBundle:Production")->findProductionInWeek($week["week"], $year);
        }


        return $this->render('AppBundle:Production:basket.html.twig', array(
            'weeks' => $weeks,
            'year' => $year,
            'baskets' => $baskets

        ));
    }

    public function basketDetailAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $basket = $em->getRepository('AppBundle:Basket')->find($id);
        $amount=0;
        foreach ($basket->getProductions() as $production) {
            $amount+=$production->getAmount();
        }
        $basket->setAmount($amount);
        $em->persist($basket);
        $em->flush();


        return $this->render('AppBundle:Production:basket_detail.html.twig', array(
            'basket' => $basket,
            'year' => $basket->getDate()->format('Y')

        ));
    }
}
