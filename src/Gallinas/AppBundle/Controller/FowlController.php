<?php

namespace Gallinas\AppBundle\Controller;

use Gallinas\AppBundle\Entity\Collect;
use Gallinas\AppBundle\Entity\Gift;
use Gallinas\AppBundle\Entity\Sale;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Fowl;
use Gallinas\AppBundle\Form\FowlType;

/**
 * Fowl controller.
 *
 */
class FowlController extends Controller
{

    /**
     * Lists all Fowl entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Fowl')->findAll();

        return $this->render('AppBundle:Fowl:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Fowl entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Fowl();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('fowl_show', array('id' => $entity->getId())));
        }

        return $this->render('AppBundle:Fowl:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Fowl entity.
     *
     * @param Fowl $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Fowl $entity)
    {
        $form = $this->createForm(new FowlType(), $entity, array(
            'action' => $this->generateUrl('fowl_create'),
            'method' => 'POST',
        ));

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Fowl entity.
     *
     */
    public function newAction()
    {
        $entity = new Fowl();
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Fowl:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Fowl entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Fowl')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Fowl entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Fowl:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Fowl entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Fowl')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Fowl entity.');
        }
        if ($entity->getPutDownDate())
        {
            $entity->setPutDownDate($entity->getPutDownDate()->format('Y-m-d'));
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Fowl:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Fowl entity.
     *
     * @param Fowl $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Fowl $entity)
    {
        $form = $this->createForm(new FowlType(), $entity, array(
            'action' => $this->generateUrl('fowl_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Fowl entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Fowl')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Fowl entity.');
        }

        if ($entity->getPutDownDate())
        {
            $entity->setPutDownDate($entity->getPutDownDate()->format('Y-m-d'));
        }
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            if ($entity->getPutDownDate())
            {
                $entity->setPutDownDate(new \DateTime($entity->getPutDownDate()));
            }
            if ($entity->getFowlStatus()->getId() == 1)
            {
                $entity->setPutDownDate(null);
            }

            if ($entity->isRelated())
            {

                if ($entity->getCollect())
                {
                    $collect_remove = $entity->getCollect();
                    $entity->setCollect(null);
                    $em->remove($collect_remove);
                } elseif ($entity->getGift())
                {
                    $gift_remove = $entity->getGift();
                    $entity->setGift(null);
                    $em->remove($gift_remove);
                } elseif ($entity->getSale())
                {
                    $sale_remove = $entity->getSale();
                    $entity->setSale(null);
                    $em->remove($sale_remove);
                }
            }

            if (!$entity->getFowlDestination() == null)
            {
                if ($entity->getFowlDestination()->getId() == 1)
                {
                    $sale = new Sale();
                    $sale->setPurchaser($entity->getFowlPurchaser());
                    $sale->setProduct($entity->getBatch()->getProduct());
                    $sale->setSaleDate(new \DateTime($entity->getPutDownDate()->format('Y-m-d')));
                    $sale->setAmount($entity->getCarcassWeight());
                    $sale->setSinglePrice($entity->getSalePrice());
                    $sale->setWeek(date('W', strtotime($entity->getPutDownDate()->format('Y-m-d'))));
                    $sale->setPaid(false);
                    $sale->setTotalPrice($entity->getSalePrice() * $entity->getCarcassWeight());
                    $sale->setUnity($em->getRepository('AppBundle:Unity')->find(2));

                    $em->persist($sale);

                    $entity->setSale($sale);
                } else if ($entity->getFowlDestination()->getId() == 2)
                {
                    $gift = new Gift();
                    $gift->setSinglePrice($entity->getSalePrice());
                    $gift->setTotalPrice($entity->getSalePrice()*$entity->getCarcassWeight());
                    $gift->setRecipient($entity->getFowlRecipient());
                    $gift->setProduct($entity->getBatch()->getProduct());
                    $gift->setGiftDate(new \DateTime($entity->getPutDownDate()->format('Y-m-d')));
                    $gift->setAmount($entity->getCarcassWeight());
                    $gift->setWeek(date('W', strtotime($entity->getPutDownDate()->format('Y-m-d'))));
                    $gift->setUnity($em->getRepository('AppBundle:Unity')->find(2));

                    $em->persist($gift);

                    $entity->setGift($gift);
                } else if ($entity->getFowlDestination()->getId() == 3)
                {
                    $collect = new Collect();
                    $collect->setUser($entity->getFowlUser());
                    $collect->setProduct($entity->getBatch()->getProduct());
                    $collect->setCollectDate(new \DateTime($entity->getPutDownDate()->format('Y-m-d')));
                    $collect->setAmount($entity->getCarcassWeight());
                    $collect->setWeek(date('W', strtotime($entity->getPutDownDate()->format('Y-m-d'))));
                    $collect->setUnity($em->getRepository('AppBundle:Unity')->find(2));

                    $em->persist($collect);

                    $entity->setCollect($collect);
                }

            }
            $em->persist($entity);

            $em->flush();

            return $this->redirect($this->generateUrl('batch_show', array('id' => $entity->getBatch()->getId())));
        }

        return $this->render('AppBundle:Fowl:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Fowl entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Fowl')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Fowl entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('fowl'));
    }

    /**
     * Creates a form to delete a Fowl entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('fowl_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Delete'))
            ->getForm();
    }
}
