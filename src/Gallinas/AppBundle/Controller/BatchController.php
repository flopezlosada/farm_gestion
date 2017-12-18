<?php
 
namespace Gallinas\AppBundle\Controller;

use Gallinas\AppBundle\Entity\Fowl;
use Gallinas\AppBundle\Form\BatchEditType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Batch;
use Gallinas\AppBundle\Form\BatchType;
use Symfony\Component\Validator\Constraints\DateTime;

/**
 * Batch controller.
 *
 */
class BatchController extends Controller
{

    /**
     * Lists all Batch entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Batch')->findAll();

        return $this->render('AppBundle:Batch:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Batch entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Batch();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();

            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $entity->setReceiptDate(new \DateTime($entity->getReceiptDate()));
            $batch_status = $em->getRepository('AppBundle:BatchStatus')->find(1);
            $entity->setBatchStatus($batch_status);

            $fowl_status = $em->getRepository('AppBundle:FowlStatus')->find(1);
            for ($i = 1; $i <= $entity->getAmount(); $i++)
            {
                $fowl = new Fowl();
                $fowl->setBatch($entity);
                $fowl->setFowlStatus($fowl_status);
                $em->persist($fowl);
            }
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Batch:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Batch entity.
     *
     * @param Batch $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Batch $entity)
    {
        $form = $this->createForm(new BatchType(), $entity, array(
            'action' => $this->generateUrl('batch_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Batch entity.
     *
     */
    public function newAction()
    {
        $entity = new Batch();
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Batch:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Batch entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Batch')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }

        $start_time = 0;
        $start_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        $end_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        $consumption = array();//guarda todos los consumos diarios calculados (cada 14 días)
        while ($start_time <= $entity->getProductionTime())
        {
            $end_time = $start_time + 14;
            //echo "tiempo de producción: " . $entity->getProductionTime()." start_time: ".$start_time."<br>";
            $start_date->add(date_interval_create_from_date_string($start_time . ' days'));
            $end_date->add(date_interval_create_from_date_string($end_time . ' days'));
            //echo $end_date->format("Y-m-d") . "<br>";
            $feed_amount = $em->getRepository('AppBundle:Batch')->getFeedAmountInInterval($entity, $start_date, $end_date);//cantidad de pienso consumido en el intervalo
            $fowls = $em->getRepository('AppBundle:Batch')->getFowlsAliveInInterval($entity, $end_date);//animales vivos en el intervalo.
            //echo "comida: " . $feed_amount . " y bichos: " . $fowls . "<br>";
            @$consumption[] = $feed_amount / ($fowls * 14);
            $start_time += 14;
            $start_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
            $end_date = new \DateTime($entity->getReceiptDate()->format('Y-m-d'));
        }


        $average_consumption = array_sum($consumption) *1000/ count($consumption);

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Batch:show.html.twig', array(
            'entity' => $entity,
            'average_consumption'=> $average_consumption,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Batch entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Batch')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }

        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));
        $entity->setReceiptDate($entity->getReceiptDate()->format('Y-m-d'));
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Batch:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Batch entity.
     *
     * @param Batch $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Batch $entity)
    {
        $form = $this->createForm(new BatchEditType(), $entity, array(
            'action' => $this->generateUrl('batch_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Batch entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Batch')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }

        $entity->setPurchaseDate($entity->getPurchaseDate()->format('Y-m-d'));
        $entity->setReceiptDate($entity->getReceiptDate()->format('Y-m-d'));
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $entity->setPurchaseDate(new \DateTime($entity->getPurchaseDate()));
            $entity->setReceiptDate(new \DateTime($entity->getReceiptDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('batch_edit', array('id' => $id)));
        }

        return $this->render('AppBundle:Batch:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Batch entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Batch')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Batch entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('batch'));
    }

    /**
     * Creates a form to delete a Batch entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('batch_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }

    public function closeAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Batch')->find($id);
        $batch_status = $em->getRepository('AppBundle:BatchStatus')->find(2);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }


        $entity->setBatchStatus($batch_status);
        if ($entity->getFinalizationDate() == null)
        {
            $entity->setFinalizationDate(new \DateTime());
        }
        $em->persist($entity);
        $em->flush();

        $this->get('session')->getFlashBag()->add(
            'notice',
            'El lote se ha actualizado correctamente.'
        );

        return $this->redirect($this->generateUrl("batch_show", array('id' => $id)));
    }

    public function reactivateAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Batch')->find($id);
        $batch_status = $em->getRepository('AppBundle:BatchStatus')->find(1);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Batch entity.');
        }


        $entity->setBatchStatus($batch_status);
        $em->persist($entity);
        $em->flush();

        $this->get('session')->getFlashBag()->add(
            'notice',
            'El lote se ha actualizado correctamente.'
        );

        return $this->redirect($this->generateUrl("batch_show", array('id' => $id)));
    }

}
