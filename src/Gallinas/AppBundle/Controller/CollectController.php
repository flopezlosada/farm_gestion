<?php

namespace Gallinas\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Gallinas\AppBundle\Entity\Collect;
use Gallinas\AppBundle\Form\CollectType;

/**
 * Collect controller.
 *
 */
class CollectController extends Controller
{

    /**
     * Lists all Collect entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('AppBundle:Collect')->findAll();

        return $this->render('AppBundle:Collect:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new Collect entity.
     *
     */
    public function createAction(Request $request)
    {
        $entity = new Collect();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity->setWeek(date('W', strtotime($entity->getCollectDate())));
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Collect:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a Collect entity.
     *
     * @param Collect $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Collect $entity)
    {
        $form = $this->createForm(new CollectType(), $entity, array(
            'action' => $this->generateUrl('collect_create'),
            'method' => 'POST',
            'em' => $this->getDoctrine()->getManager()
        ));

        $form->add('submit', 'submit', array('label' => 'Añadir'));

        return $form;
    }

    /**
     * Displays a form to create a new Collect entity.
     *
     */
    public function newAction()
    {
        $entity = new Collect();
        $entity->setUser($this->getUser());
        $form = $this->createCreateForm($entity);

        return $this->render('AppBundle:Collect:new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Collect entity.
     *
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Collect')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Collect:show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Collect entity.
     *
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Collect')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('AppBundle:Collect:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Collect entity.
     *
     * @param Collect $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Collect $entity)
    {
        $form = $this->createForm(new CollectType(), $entity, array(
            'action' => $this->generateUrl('collect_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', 'submit', array('label' => 'Actualizar'));

        return $form;
    }

    /**
     * Edits an existing Collect entity.
     *
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('AppBundle:Collect')->find($id);

        if (!$entity)
        {
            throw $this->createNotFoundException('Unable to find Collect entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $entity->setCollectDate($entity->getCollectDate()->format('Y-m-d'));

        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid())
        {
            $entity->setCollectDate(new \DateTime($entity->getCollectDate()));
            $em->flush();

            return $this->redirect($this->generateUrl('calendar'));
        }

        return $this->render('AppBundle:Collect:edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Collect entity.
     *
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid())
        {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('AppBundle:Collect')->find($id);

            if (!$entity)
            {
                throw $this->createNotFoundException('Unable to find Collect entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('calendar'));
    }

    /**
     * Creates a form to delete a Collect entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('collect_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', 'submit', array('label' => 'Borrar'))
            ->getForm();
    }

    public function luciosAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        if ($request->get('selected_dichotomous_key_id'))
        {
            $id = $request->get('selected_dichotomous_key_id');

            echo $id;
            $dichotomous_keys = $em->getRepository('AppBundle:Unity')->find($id);

        } else
        {
            echo "cawsdadva";
            $dichotomous_keys = $em->getRepository('AppBundle:Unity')->findAll();
        }
        echo count($dichotomous_keys);


        $form = $this->createFormBuilder(null, array('attr' => array('id' => 'fish_species_identify_form')))
            ->add('dichotomous_key', 'entity', array(
                'choices' => $dichotomous_keys,
                'class' => 'Gallinas\AppBundle\Entity\Unity',

                'multiple' => false,
                'expanded' => true))
            ->add('submit', 'submit', array('label' => 'Continuar'))
            ->getForm();


        return $this->render('AppBundle:Default:identify.html.twig', array(
            'dichotomous_keys' => $dichotomous_keys,
            'form' => $form->createView()
        ));
    }
}
