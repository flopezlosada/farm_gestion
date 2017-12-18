<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Sack;
use Gallinas\AppBundle\Form\SackType;

/**
 * Sack controller.
 *
 */
class SackController extends Controller
{

    /**
     * Lists all Sack entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Sack')->findAll();

        return $this->render('AppBundle:Sack:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Sack entity.
     *
     */
    public function createAction($batch_id, Request $request)
    {
        $entity = new Sack();
        $em = $this->getDoctrine()->getManager();
        $batch = $em->getRepository("AppBundle:Batch")->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);

        $form->handleRequest($request);



        //$sack_product = $em->getRepository("AppBundle:Product")->find($batch->getSackProductId());
        //$purchase = $em->getRepository("AppBundle:Sack")->getPurchase($sack_product);


        if ($form->isValid())
        {
            $entity->setDeliveryDate(new \DateTime($entity->getDeliveryDate()));
            if ($entity->getWeight() >= $entity->getPurchase()->getAvailableAmount())
            {
                $entity->setWeight($entity->getPurchase()->getAvailableAmount());
                $entity->getPurchase()->setAssigned(true);
                $em->persist($entity->getPurchase());
            }
            $entity->setBatch($batch);
            $entity->setProduct($entity->getPurchase()->getProduct());
            //$entity->setPurchase($purchase);
            $entity->setTotalPrice($entity->getPurchase()->getSinglePrice() * $entity->getWeight());
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $batch->getId())));
        }

        return $this->render('AppBundle:Sack:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'batch' => $batch,
            //'max_weight' => $purchase->getAvailableAmount(),
            //'sack_product' => $sack_product
        ));
    }

    /**
     * Creates a form to create a Sack entity.
     *
     * @param Sack $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Sack $entity, $batch)
    {
        $form = $this->createForm(new SackType($batch), $entity, array(
            'action' => $this->generateUrl('sack_create', array('batch_id' => $batch->getId())),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Sack entity.
     *
     */
    public function newAction($batch_id)
    {
        $entity = new Sack();


        $em = $this->getDoctrine()->getManager();
        $batch = $em->getRepository("AppBundle:Batch")->find($batch_id);
        $form = $this->createCreateForm($entity, $batch);
        $sack_product = $em->getRepository("AppBundle:Product")->find($batch->getSackProductId());
        $purchase = $em->getRepository("AppBundle:Sack")->getPurchase($sack_product);
        $available_amount=0;
        if ($purchase)
        {
            $available_amount=$purchase->getAvailableAmount();
        }


        return $this->render('AppBundle:Sack:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'batch' => $batch,
            'max_weight' => $available_amount,
            'sack_product' => $sack_product
        ));
    }

    /**
     * Finds and displays a Sack entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sack')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sack entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Sack:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Sack entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sack')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sack entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Sack:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Sack entity.
     *
     * @param Sack $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Sack $entity)
    {
        $form = $this->createForm(new SackType(), $entity, array(
            'action' => $this->generateUrl('sack_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Sack entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sack')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sack entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $em->flush();

            return $this->redirect($this->generateUrl('sack_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Sack:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Sack entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Sack')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Sack entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('sack'));
    }

    /**
     * Creates a form to delete a Sack entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('sack_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }

    public function fastDeleteAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Sack')->find($id);
        $batch = $entity->getBatch();

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Sack entity.');
        }
        $purchase = $entity->getPurchase();
        $purchase->setAssigned(false);
        $em->remove($entity);
        $em->persist($purchase);
        $em->flush();

        return $this->redirect($this->generateUrl('batch_show', array('id' => $batch->getId())));
    }
}
