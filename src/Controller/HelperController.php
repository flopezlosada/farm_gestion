<?php

namespace App\Controller;

use App\Entity\Helper;
use App\Form\HelperType;
use App\Repository\HelperRepository;
use App\Repository\HelperSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de los voluntarios de acogida del albergue ({@see Helper}). Las estancias
 * de cada uno se gestionan desde su ficha, en {@see StayController}.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
#[Route('/gestion/albergue/helpers')]
#[IsGranted('ROLE_GESTION_ALBERGUE')]
class HelperController extends AbstractController
{
    /**
     * Listado filtrable de voluntarios (por texto y procedencia).
     *
     * @param Request $request
     * @param HelperRepository $helperRepository
     * @param HelperSourceRepository $sourceRepository
     * @return Response
     */
    #[Route('/', name: 'helper_index', methods: ['GET'])]
    public function index(
        Request $request,
        HelperRepository $helperRepository,
        HelperSourceRepository $sourceRepository,
    ): Response {
        $term = trim((string) $request->query->get('q', ''));
        $sourceId = $request->query->get('source');
        $sourceId = ($sourceId === null || $sourceId === '') ? null : (int) $sourceId;

        $helpers = $helperRepository->search($term, $sourceId);

        return $this->render('helper/index.html.twig', [
            'helpers' => $helpers,
            'sources' => $sourceRepository->findBy([], ['name' => 'ASC']),
            'q' => $term,
            'selected_source' => $sourceId,
        ]);
    }

    /**
     * Ficha del voluntario: sus datos y el historial de estancias.
     *
     * @param Helper $helper
     * @return Response
     */
    #[Route('/{id}', name: 'helper_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Helper $helper): Response
    {
        // Estancias de más reciente a más antigua (por llegada).
        $stays = $helper->getStays()->toArray();
        usort($stays, fn ($a, $b) => $b->getArrivalDate() <=> $a->getArrivalDate());

        return $this->render('helper/show.html.twig', [
            'helper' => $helper,
            'stays' => $stays,
        ]);
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'helper_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $helper = new Helper();
        $form = $this->createForm(HelperType::class, $helper);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($helper);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Voluntario «%s» creado.', $helper->getName()));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        return $this->render('helper/new.html.twig', [
            'helper' => $helper,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param Helper $helper
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'helper_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Helper $helper, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(HelperType::class, $helper);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', sprintf('Voluntario «%s» actualizado.', $helper->getName()));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        return $this->render('helper/edit.html.twig', [
            'helper' => $helper,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Borra un voluntario. No se permite si tiene estancias registradas: primero
     * hay que retirarlas (un borrado en cascada se llevaría su historial).
     *
     * @param Request $request
     * @param Helper $helper
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'helper_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Helper $helper, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $helper->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        if ($helper->getStays()->count() > 0) {
            $this->addFlash('error', sprintf(
                'No se puede borrar «%s»: tiene %d estancia(s) registrada(s). Retíralas antes.',
                $helper->getName(),
                $helper->getStays()->count(),
            ));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        $entityManager->remove($helper);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Voluntario «%s» borrado.', $helper->getName()));

        return $this->redirectToRoute('helper_index');
    }
}
