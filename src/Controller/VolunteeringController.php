<?php

namespace App\Controller;

use App\Entity\VolunteerCall;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Form\VolunteerCategoryType;
use App\Form\VolunteerOfferType;
use App\Repository\VolunteerCallRepository;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerOfferRepository;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del voluntariado: publicar tareas, ver quién se apunta, cerrarlas y
 * pedir gente.
 *
 * Lectura con ROLE_GESTION_SOCIXS y escritura con ROLE_GESTION_SOCIXS_EDIT,
 * como el resto del área de socixs. Todo bajo el toggle del módulo.
 */
#[Route('/gestion/voluntariado')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
#[IsGranted('FEATURE_VOLUNTEERING')]
class VolunteeringController extends AbstractController
{
    /** Cuántos días atrás se miran las tareas ya hechas en el listado. */
    private const RECENT_DAYS = 60;

    /**
     * Las tareas: lo que falta por cerrar, lo que viene y lo que ya se hizo.
     */
    #[Route('', name: 'volunteering_index', methods: ['GET'])]
    public function index(VolunteerOfferRepository $offers): Response
    {
        $now = new \DateTime();

        return $this->render('Volunteering/index.html.twig', [
            'upcoming' => $offers->findUpcoming($now),
            // Sólo quedan aquí las que nadie ha confirmado por su cuenta: en
            // cuanto alguien dice "sí, la hice" desde su panel, la tarea deja
            // de ser trabajo pendiente para administración.
            'pending_closure' => $offers->findPendingClosure($now),
            'recently_done' => $offers->findRecentlyDone(
                (clone $now)->modify(sprintf('-%d days', self::RECENT_DAYS))
            ),
        ]);
    }

    /**
     * Publicar una tarea nueva.
     */
    #[Route('/nueva', name: 'volunteering_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $offer = (new VolunteerOffer())->setCreatedBy($this->getUser());
        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($offer);
            $em->flush();
            $this->addFlash('success', 'Tarea creada.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * Una tarea: sus datos, quién se ha apuntado y los avisos que se han
     * mandado por ella.
     */
    #[Route('/{id}', name: 'volunteering_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        VolunteerOffer $offer,
        VolunteerCallRepository $calls,
        VolunteerAudienceResolver $audience,
    ): Response {
        $sent = $calls->sentScopes($offer);

        return $this->render('Volunteering/show.html.twig', [
            'offer' => $offer,
            'sent_scopes' => $sent,
            // Cuánta gente recibiría el aviso general, para que quien pulsa el
            // botón vea el número ANTES de molestar a media asociación.
            'everyone_count' => $audience->count($offer, VolunteerCall::SCOPE_EVERYONE),
            'everyone_sent' => \in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true),
        ]);
    }

    /**
     * Editar una tarea.
     */
    #[Route('/{id}/editar', name: 'volunteering_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function edit(Request $request, VolunteerOffer $offer, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Tarea actualizada.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }

    /**
     * Avisar a TODA la asociación de que hace falta gente para esta tarea.
     *
     * Este alcance no lo abre nunca el automatismo, y no por prudencia técnica:
     * el permiso de notificaciones del navegador se pierde una vez y para
     * siempre, así que gastarlo tiene que ser una decisión de alguien que sabe
     * que la cosa lo merece. Un automatismo no distingue "falta gente para la
     * plantación" de "si no vienen se pierde la cosecha".
     */
    #[Route('/{id}/avisar-a-todxs', name: 'volunteering_notify_everyone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function notifyEveryone(
        Request $request,
        VolunteerOffer $offer,
        VolunteerCallNotifier $notifier,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_notify', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $call = $notifier->dispatch(
            $offer,
            VolunteerCall::SCOPE_EVERYONE,
            $this->getUser(),
            new \DateTimeImmutable()
        );

        if (null === $call) {
            $this->addFlash('warning', 'No se ha avisado a nadie: o ya se avisó a todo el mundo por esta tarea, o no queda nadie a quien avisar.');
        } else {
            $this->addFlash('success', sprintf('Aviso enviado a %d socix(s).', $call->getRecipients()));
        }

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * Cerrar una tarea ya pasada: decir quién fue y quién no.
     *
     * Mientras no se cierra, no computa horas a nadie: el `attended` a null es
     * significativo, y así olvidarse de cerrar una tarea no infla el contador de
     * nadie a base de gente que se apuntó y no apareció.
     */
    #[Route('/{id}/cerrar', name: 'volunteering_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function close(Request $request, VolunteerOffer $offer, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('volunteering_close', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $attended = array_map('intval', (array) $request->request->all('attended'));
        $counted = 0;

        foreach ($offer->getSignups() as $signup) {
            if ($signup->isCancelled()) {
                continue;
            }

            $wentThere = \in_array($signup->getId(), $attended, true);

            if ($wentThere) {
                ++$counted;
            }

            // Sólo se toca lo que cambia. Sin esto, cerrar una tarea que alguien
            // ya había confirmado desde su panel la reescribiría como "lo puso
            // gestión" y se perdería el rastro de que lo dijo quien fue — que es
            // justo lo que distingue una tarea que se cerró sola de una que hubo
            // que perseguir.
            if ($signup->getAttended() === $wentThere) {
                continue;
            }

            $wentThere
                ? $signup->confirmAttendance(VolunteerSignup::SOURCE_MANAGER)
                : $signup->markAbsent(VolunteerSignup::SOURCE_MANAGER);
        }

        $em->flush();
        $this->addFlash('success', sprintf('Tarea cerrada: %d persona(s) con horas computadas.', $counted));

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * El catálogo de tipos de trabajo.
     */
    #[Route('/categorias/listado', name: 'volunteering_categories', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function categories(
        Request $request,
        VolunteerCategoryRepository $categories,
        EntityManagerInterface $em,
    ): Response {
        $category = new VolunteerCategory();
        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Categoría creada.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/categories.html.twig', [
            'categories' => $categories->findBy([], ['name' => 'ASC']),
            'form' => $form->createView(),
        ]);
    }

    /**
     * Editar una categoría.
     */
    #[Route('/categorias/{id}/editar', name: 'volunteering_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function editCategory(Request $request, VolunteerCategory $category, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Categoría actualizada.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
        ]);
    }
}
