<?php

namespace App\Controller;

use App\Entity\DeliveryException;
use App\Form\DeliveryExceptionType;
use App\Repository\DeliveryExceptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de las excepciones de calendario de reparto (festivos, cierres,
 * traslados de día). Permite planificarlas por adelantado apuntando a un
 * ciclo futuro y, opcionalmente, a un nodo concreto.
 *
 * Vive bajo /gestion/reparto/excepciones; no choca con delivery_show
 * (/gestion/reparto/{basketId} exige \d+).
 *
 * Sub-fase 8.8d (2026-05-27).
 */
#[Route('/gestion/reparto/excepciones')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryExceptionController extends AbstractController
{
    /**
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/', name: 'delivery_exception_index', methods: ['GET'])]
    public function index(DeliveryExceptionRepository $repository): Response
    {
        $all = $repository->createQueryBuilder('e')
            ->join('e.basket', 'b')
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Separa por el viernes-ciclo: las próximas son las planificables;
        // las pasadas, histórico (más reciente primero).
        $today = new \DateTimeImmutable('today');
        $upcoming = [];
        $past = [];
        foreach ($all as $exception) {
            if ($exception->getBasket()->getDate() >= $today) {
                $upcoming[] = $exception;
            } else {
                $past[] = $exception;
            }
        }

        return $this->render('delivery_exception/index.html.twig', [
            'upcoming' => $upcoming,
            'past' => array_reverse($past),
            'total' => count($all),
        ]);
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/new', name: 'delivery_exception_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, DeliveryExceptionRepository $repository): Response
    {
        $exception = new DeliveryException();
        $form = $this->createForm(DeliveryExceptionType::class, $exception);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isDuplicate($repository, $exception)) {
                $this->addFlash('error', 'Ya existe una excepción para ese ciclo y nodo. Edítala en vez de crear otra.');
            } else {
                $entityManager->persist($exception);
                $entityManager->flush();
                $this->addFlash('success', 'Excepción de reparto creada.');

                return $this->redirectToRoute('delivery_exception_index');
            }
        }

        return $this->render('delivery_exception/new.html.twig', [
            'exception' => $exception,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param DeliveryException $exception
     * @param EntityManagerInterface $entityManager
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/{id}/edit', name: 'delivery_exception_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DeliveryException $exception, EntityManagerInterface $entityManager, DeliveryExceptionRepository $repository): Response
    {
        $form = $this->createForm(DeliveryExceptionType::class, $exception);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isDuplicate($repository, $exception)) {
                $this->addFlash('error', 'Ya existe otra excepción para ese ciclo y nodo.');
            } else {
                $entityManager->flush();
                $this->addFlash('success', 'Excepción de reparto actualizada.');

                return $this->redirectToRoute('delivery_exception_index');
            }
        }

        return $this->render('delivery_exception/edit.html.twig', [
            'exception' => $exception,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param DeliveryException $exception
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'delivery_exception_delete', methods: ['POST'])]
    public function delete(Request $request, DeliveryException $exception, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $exception->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('delivery_exception_index');
        }

        $entityManager->remove($exception);
        $entityManager->flush();
        $this->addFlash('success', 'Excepción de reparto borrada.');

        return $this->redirectToRoute('delivery_exception_index');
    }

    /**
     * ¿Existe ya otra excepción para el mismo (ciclo, nodo)? Suple el
     * UNIQUE(basket, node) que MySQL no garantiza con node_id NULL.
     *
     * @param DeliveryExceptionRepository $repository
     * @param DeliveryException $exception Excepción que se intenta guardar.
     * @return bool True si chocaría con una fila distinta ya existente.
     */
    private function isDuplicate(DeliveryExceptionRepository $repository, DeliveryException $exception): bool
    {
        $existing = $repository->findOneExact($exception->getBasket(), $exception->getNode());

        return $existing !== null && $existing->getId() !== $exception->getId();
    }
}
