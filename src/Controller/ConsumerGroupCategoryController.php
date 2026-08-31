<?php

namespace App\Controller;

use App\Entity\ConsumerGroupCategory;
use App\Form\ConsumerGroupCategoryType;
use App\Repository\ConsumerGroupCategoryRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD mínimo de categorías de producto del grupo de consumo. Entidad propia para
 * poder crecer sin fricción (añadir categorías según hagan falta).
 */
#[Route('/gestion/consumer-group/categories')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]
class ConsumerGroupCategoryController extends AbstractController
{
    /**
     * Listado de categorías + formulario de alta en la misma pantalla.
     */
    #[Route('/', name: 'consumer_group_category_index', methods: ['GET', 'POST'])]
    public function index(Request $request, ConsumerGroupCategoryRepository $categories, EntityManagerInterface $em): Response
    {
        $category = new ConsumerGroupCategory();
        $form = $this->createForm(ConsumerGroupCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Categoría creada.');

            return $this->redirectToRoute('consumer_group_category_index');
        }

        return $this->render('consumer_group_category/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'form'       => $form->createView(),
        ]);
    }

    /**
     * Editar una categoría.
     */
    #[Route('/{id}/edit', name: 'consumer_group_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupCategory $category, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ConsumerGroupCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Categoría actualizada.');

            return $this->redirectToRoute('consumer_group_category_index');
        }

        return $this->render('consumer_group_category/edit.html.twig', [
            'category' => $category,
            'form'     => $form->createView(),
        ]);
    }

    /**
     * Borrar una categoría. Los productos que la usaban quedan sin categoría
     * (FK SET NULL), no se pierden.
     */
    #[Route('/{id}/delete', name: 'consumer_group_category_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupCategory $category, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_category_delete_'.$category->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_category_index');
        }

        try {
            $em->remove($category);
            $em->flush();
            $this->addFlash('success', 'Categoría borrada.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'No se pudo borrar la categoría.');
        }

        return $this->redirectToRoute('consumer_group_category_index');
    }
}
