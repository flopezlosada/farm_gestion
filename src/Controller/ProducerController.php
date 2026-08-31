<?php

namespace App\Controller;

use App\Entity\Producer;
use App\Form\ProducerType;
use App\Repository\ProducerRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de PRODUCTORES del grupo de consumo y su catálogo (persistente, reutilizable
 * entre rondas). La gestiona la comisión.
 *
 * Acceso: gateado por el feature-flag de rodaje y por ROLE_GESTION_GRUPO_CONSUMO; la
 * escritura (POST/PUT/PATCH/DELETE) la exige access_control con _EDIT sobre
 * ^/gestion/consumer-group.
 */
#[Route('/gestion/consumer-group/producers')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]
class ProducerController extends AbstractController
{
    /**
     * Listado de productores (activos primero, luego por nombre).
     */
    #[Route('/', name: 'consumer_group_producer_index', methods: ['GET'])]
    public function index(ProducerRepository $producers): Response
    {
        return $this->render('producer/index.html.twig', [
            'producers' => $producers->findAllOrdered(),
        ]);
    }

    /**
     * Alta de un productor con su catálogo.
     */
    #[Route('/new', name: 'consumer_group_producer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $producer = new Producer();
        $form = $this->createForm(ProducerType::class, $producer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($producer);
            $em->flush();
            $this->addFlash('success', 'Productor creado. Añade sus productos al catálogo desde su ficha.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        return $this->render('producer/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Ficha del productor con su catálogo.
     */
    #[Route('/{id}', name: 'consumer_group_producer_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Producer $producer): Response
    {
        return $this->render('producer/show.html.twig', [
            'producer' => $producer,
        ]);
    }

    /**
     * Editar el productor y su catálogo.
     */
    #[Route('/{id}/edit', name: 'consumer_group_producer_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Producer $producer, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProducerType::class, $producer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Productor actualizado.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        return $this->render('producer/edit.html.twig', [
            'producer' => $producer,
            'form'     => $form->createView(),
        ]);
    }

    /**
     * Borrar un productor. Si tiene rondas (FK RESTRICT), la BBDD lo impide: en ese
     * caso se marca inactivo en su lugar. Guard server-side, no solo UI.
     */
    #[Route('/{id}/delete', name: 'consumer_group_producer_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Producer $producer, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_producer_delete_'.$producer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        try {
            $em->remove($producer);
            $em->flush();
            $this->addFlash('success', 'Productor borrado.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'No se puede borrar un productor con rondas. Márcalo como inactivo en su lugar.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        return $this->redirectToRoute('consumer_group_producer_index');
    }
}
