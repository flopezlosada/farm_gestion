<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use App\Form\TaskAproxType;
use App\Form\TaskDateType;
use App\Form\TaskPeriodicType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use App\Entity\Task;
use App\Form\TaskType;

/**
 * Task controller.
 *
 */
class TaskController extends AbstractController
{

    /**
     * Lists all Task entities.
     *
     */
    public function index()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('App:Task')->findAll();
        $pending_tasks = $em->getRepository("App:Task")->findPending(date('n'), date('Y'));
        $aprox_tasks = $em->getRepository("App:Task")->findPending(date('n'), date('Y'), 3);
        $periodic_tasks = $em->getRepository("App:Task")->findPending(date('n'), date('Y'), 4);
        $date_tasks = $em->getRepository("App:Task")->findPending(date('n'), date('Y'), 2);
        $punctual_tasks = $em->getRepository("App:Task")->findPending(null, null, 1);//sin fecha
        $crop_tasks = $em->getRepository("App:Task")->findCrop(date('n'), date('Y'));
        $ended_tasks = $em->getRepository("App:Task")->findEnded();
        $user_tasks = $em->getRepository("App:Task")->findUserTask($this->getUser(), date('n'), date('Y'));

        return $this->render('Task/index.html.twig', array(
            'entities' => $entities,
            'pending_tasks' => $pending_tasks,
            'aprox_tasks' => $aprox_tasks,
            'periodic_tasks' => $periodic_tasks,
            'date_tasks' => $date_tasks,
            'punctual_tasks' => $punctual_tasks,
            'crop_tasks' => $crop_tasks,
            'ended_tasks' => $ended_tasks,
            'user_tasks' => $user_tasks
        ));
    }

    /**
     * Creates a new Task entity.
     *
     */
    public function create(Request $request, $task_type_id)
    {
        $entity = new Task();
        $form = $this->createCreateForm($entity, $task_type_id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $task_type = $em->getRepository("App:TaskType")->find($task_type_id);
            $entity->setTaskType($task_type);
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task_show', array('id' => $entity->getId())));
        }

        return $this->render('Task/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }


    /**
     * Creates a form to create a Task entity.
     *
     * @param Task $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Task $entity, $task_type_id)
    {
        $form = $this->createForm(TaskType::class, $entity, array(
            'action' => $this->generateUrl('task_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }


    /**
     * Displays a form to create a new Task entity.
     *
     */
    public function new($task_type_id)
    {
        $entity = new Task();
        $form = $this->createCreateForm($entity, $task_type_id);

        return $this->render('Task/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function newAprox()
    {
        $entity = new Task();
        $form = $this->createForm(TaskAproxType::class, $entity, array(
            'action' => $this->generateUrl('task_aprox_create', array('task_type_id' => 3)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $this->render('Task/new_aprox.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function createAprox($task_type_id, Request $request)
    {
        $entity = new Task();
        $form = $this->createForm(TaskAproxType::class, $entity, array(
            'action' => $this->generateUrl('task_aprox_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));
        $form->add('submit', SubmitType::class, array('label' => 'Create'));
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $task_type = $em->getRepository("App:TaskType")->find($task_type_id);
            $entity->setTaskType($task_type);
            if ($entity->getExpectedDate()) {
                $entity->setExpectedDate(new \DateTime($entity->getExpectedDate()));
            }

            if ($entity->getMonth() >= date('n')) {
                $entity->setYear(date('Y'));
            } else {
                $entity->setYear(date('Y') + 1);
            }
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task'));
        }

        return $this->render('Task/new_aprox.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function newDate($task_type_id)
    {
        $entity = new Task();
        $form = $this->createForm(TaskDateType::class, $entity, array(
            'action' => $this->generateUrl('task_date_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $this->render('Task/new_date.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function createDate($task_type_id, Request $request)
    {
        $entity = new Task();
        $form = $this->createForm(TaskDateType::class, $entity, array(
            'action' => $this->generateUrl('task_date_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));
        $form->handleRequest($request);

        if ($form->isValid() && $entity->getExpectedDate()) {
            $em = $this->getDoctrine()->getManager();
            $task_type = $em->getRepository("App:TaskType")->find($task_type_id);
            $entity->setTaskType($task_type);

            $entity->setExpectedDate(new \DateTime($entity->getExpectedDate()));

            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task'));
        } else if (!$entity->getExpectedDate()) {
            $form->get('expected_date')->addError(new FormError('Este campo no debería estar vacío'));
        }

        return $this->render('Task/new_date.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    public function newPeriodic($task_type_id)
    {
        $entity = new Task();
        $form = $this->createForm(TaskPeriodicType::class, $entity, array(
            'action' => $this->generateUrl('task_periodic_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $this->render('Task/new_periodic.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }


    public function createPeriodic($task_type_id, Request $request)
    {
        $entity = new Task();
        $form = $this->createForm(TaskPeriodicType::class, $entity, array(
            'action' => $this->generateUrl('task_periodic_create', array('task_type_id' => $task_type_id)),
            'method' => 'POST',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));
        $form->handleRequest($request);

        if ($form->isValid() && $entity->getExpectedDate()) {
            $em = $this->getDoctrine()->getManager();
            $task_type = $em->getRepository("App:TaskType")->find($task_type_id);
            $entity->setTaskType($task_type);

            $entity->setExpectedDate(new \DateTime($entity->getExpectedDate()));

            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('task'));
        } else if (!$entity->getExpectedDate()) {
            $form->get('expected_date')->addError(new FormError('Este campo no debería estar vacío'));
        }

        return $this->render('Task/new_periodic.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a Task entity.
     *
     */
    public function show($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:Task')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Task entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Task/show.html.twig', array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Task entity.
     *
     */
    public function edit($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:Task')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Task entity.');
        }
        if ($entity->getExpectedDate()) {
            $entity->setExpectedDate($entity->getExpectedDate()->format('Y-m-d'));
        }
        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Task/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    public function finalize($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:Task')->find($id);
        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Task entity.');
        }
        $entity->setFinish(true);
        $entity->setRealDate(new \DateTime());
        $em->persist($entity);
        $em->flush();
        $this->addFlash("notice", "La tarea se ha finalizado correctamente");

        return $this->redirect($this->generateUrl('task'));
    }

    /**
     * Creates a form to edit a Task entity.
     *
     * @param Task $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Task $entity)
    {
        $form = $this->createForm(TaskType::class, $entity, array(
            'action' => $this->generateUrl('task_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }


    public function dashboard()
    {
        return $this->render('Task/dashboard.html.twig', array());
    }

    /**
     * Edits an existing Task entity.
     *
     */
    public function update(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('App:Task')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Task entity.');
        }
        if ($entity->getExpectedDate()) {
            $entity->setExpectedDate($entity->getExpectedDate()->format('Y-m-d'));
        }
        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isValid()) {
            if ($entity->getExpectedDate()) {
                $entity->setExpectedDate(new \DateTime($entity->getExpectedDate()));
            }
            $em->flush();

            return $this->redirect($this->generateUrl('task_edit', array('id' => $id)));
        }

        return $this->render('Task/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Task entity.
     *
     */
    public function delete(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('App:Task')->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Task entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('task'));
    }

    /**
     * Creates a form to delete a Task entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('task_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }

}
