<?php

namespace App\Controller;

use App\Entity\Node;
use App\Form\NodeType;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD del catálogo de nodos físicos de reparto.
 * Sub-fase 8.8a (2026-05-26).
 */
#[Route('/gestion/node')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class NodeController extends AbstractController
{
    /**
     * @param NodeRepository $nodeRepository
     * @return Response
     */
    #[Route('/', name: 'node_index', methods: ['GET'])]
    public function index(NodeRepository $nodeRepository): Response
    {
        return $this->render('node/index.html.twig', [
            'nodes' => $nodeRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'node_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $node = new Node();
        $form = $this->createForm(NodeType::class, $node);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($node);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Nodo "%s" creado.', $node->getName()));

            return $this->redirectToRoute('node_index');
        }

        return $this->render('node/new.html.twig', [
            'node' => $node,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param Node $node
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'node_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Node $node, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(NodeType::class, $node);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', sprintf('Nodo "%s" actualizado.', $node->getName()));

            return $this->redirectToRoute('node_index');
        }

        return $this->render('node/edit.html.twig', [
            'node' => $node,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param Node $node
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'node_delete', methods: ['POST'])]
    public function delete(Request $request, Node $node, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $node->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_index');
        }

        if ($node->getWeeklyBasketGroups()->count() > 0) {
            $this->addFlash('error', sprintf(
                'No se puede borrar "%s": tiene %d grupo(s) asociado(s). Reasígnalos antes.',
                $node->getName(),
                $node->getWeeklyBasketGroups()->count()
            ));

            return $this->redirectToRoute('node_index');
        }

        $entityManager->remove($node);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Nodo "%s" borrado.', $node->getName()));

        return $this->redirectToRoute('node_index');
    }
}
