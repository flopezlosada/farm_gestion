<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserEvent;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User controller.
 *
 */
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{

    /**
     * Lists all User entities.
     *
     */
    public function index(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\User::class)->findAll();

        return $this->render('User/index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Creates a new User entity.
     *
     */
    public function create(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em)
    {
        $entity = new User();
        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setPassword($hasher->hashPassword($entity, $form->get('plainPassword')->getData()));
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('user_show', array('id' => $entity->getId())));
        }

        return $this->render('User/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Creates a form to create a User entity.
     *
     * @param User $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(User $entity)
    {
        $form = $this->createForm(UserType::class, $entity, array(
            'action' => $this->generateUrl('user_create'),
            'method' => 'POST',
            'require_password' => true,
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new User entity.
     *
     */
    public function new()
    {
        $entity = new User();
        $form = $this->createCreateForm($entity);

        return $this->render('User/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a User entity.
     *
     */
    public function show($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\User::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        $events = $em->getRepository(\App\Entity\UserEvent::class)->findForUser($entity);

        // Resuelve los actores "gestor:<id>" a su nombre de usuaria para el
        // historial, en una sola query (mismo patrón que PartnerController::show).
        $actorIds = [];
        foreach ($events as $event) {
            $actor = $event->getActor();
            if (is_string($actor) && str_starts_with($actor, 'gestor:')) {
                $aid = (int) substr($actor, strlen('gestor:'));
                if ($aid > 0) {
                    $actorIds[$aid] = true;
                }
            }
        }
        $actorNames = [];
        if ($actorIds) {
            $actors = $em->getRepository(\App\Entity\User::class)->findBy(['id' => array_keys($actorIds)]);
            foreach ($actors as $actor) {
                $actorNames[$actor->getId()] = $actor->getUserIdentifier();
            }
        }

        return $this->render('User/show.html.twig', array(
            'entity' => $entity,
            'events' => $events,
            'actor_names' => $actorNames,
        ));
    }

    /**
     * Displays a form to edit an existing User entity.
     *
     */
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\User::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('User/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a User entity.
     *
     * @param User $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(User $entity)
    {
        $form = $this->createForm(UserType::class, $entity, array(
            'action' => $this->generateUrl('user_update', array('id' => $entity->getId())),
            'method' => 'PUT',
            'require_password' => false,
            'include_partner' => false,
        ));

        // El botón de envío lo pone la plantilla ("Guardar cambios"); no
        // añadimos SubmitType aquí para no duplicarlo vía form_rest.

        return $form;
    }

    /**
     * Edits an existing User entity. La contraseña NO se toca desde aquí:
     * el reseteo lo hace el propio usuario por magic-link en /login/forgot.
     */
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\User::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            return $this->redirect($this->generateUrl('user_show', array('id' => $id)));
        }

        return $this->render('User/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a User entity.
     *
     */
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\User::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find User entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('user'));
    }

    /**
     * Bloquea o desbloquea el acceso de una cuenta (invierte `enabled`) y deja
     * constancia en el histórico (UserEvent). No se borra la cuenta: así se
     * preserva todo su historial de granja. Una cuenta no puede bloquearse a
     * sí misma.
     *
     * @param Request $request Lleva el _token CSRF, un `reason` opcional y un
     *                         `return_to_partner` opcional (id del socix al que
     *                         volver si la acción se lanzó desde su ficha).
     * @param int     $id      Id de la cuenta a bloquear/desbloquear.
     * @return \Symfony\Component\HttpFoundation\Response Redirección a la ficha
     *         de la cuenta o a la del socix, según el origen.
     */
    public function toggleBlock(Request $request, int $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\User::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find User entity.');
        }

        // Destino de la redirección: por defecto la ficha de la cuenta, pero si
        // la acción se lanzó desde la ficha de un socix (botón "revocar/dar
        // acceso"), volvemos allí. El id se valida generando la URL nosotros
        // mismos, así que no hay riesgo de open redirect.
        $partnerReturnId = $request->request->getInt('return_to_partner');
        $back = $partnerReturnId > 0
            ? $this->redirectToRoute('partner_show', array('id' => $partnerReturnId))
            : $this->redirect($this->generateUrl('user_show', array('id' => $id)));

        if (!$this->isCsrfTokenValid('user_toggle_block_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Inténtalo de nuevo.');

            return $back;
        }

        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $entity->getId()) {
            $this->addFlash('error', 'No puedes bloquear tu propia cuenta.');

            return $back;
        }

        // Estado destino: lo contrario del actual.
        $willEnable = !$entity->isEnabled();
        $entity->setEnabled($willEnable);

        $event = new UserEvent(
            $entity,
            $willEnable ? UserEvent::TYPE_UNBLOCK : UserEvent::TYPE_BLOCK
        );
        $event->setActor($current instanceof User ? 'gestor:' . $current->getId() : UserEvent::ACTOR_SYSTEM);
        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason !== '') {
            $event->setNotes($reason);
        }
        $em->persist($event);
        $em->flush();

        $this->addFlash('success', $willEnable
            ? 'Cuenta desbloqueada: la usuaria vuelve a tener acceso.'
            : 'Cuenta bloqueada: la usuaria ya no puede entrar.');

        return $back;
    }

    /**
     * Creates a form to delete a User entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('user_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }
}
