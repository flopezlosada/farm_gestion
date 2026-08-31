<?php

namespace App\Controller;

use App\Entity\ConsumerGroupProduct;
use App\Entity\Producer;
use App\Form\ConsumerGroupProductType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del catálogo de un {@see Producer} producto a producto: alta, edición y
 * borrado. Se opera desde la FICHA del productor, no desde su formulario de alta.
 *
 * Acceso: feature-flag + ROLE_GESTION_GRUPO_CONSUMO; la escritura la exige
 * access_control con _EDIT sobre ^/gestion/consumer-group.
 */
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]
class ConsumerGroupProductController extends AbstractController
{
    /**
     * Añadir un producto al catálogo del productor.
     */
    #[Route('/gestion/consumer-group/producers/{id}/products/new', name: 'consumer_group_product_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Request $request, Producer $producer, EntityManagerInterface $em): Response
    {
        $product = new ConsumerGroupProduct();
        $product->setProducer($producer);
        $product->setSortOrder($producer->getProducts()->count());

        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($product);
            $em->flush();
            $this->addFlash('success', 'Producto añadido al catálogo.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        return $this->render('consumer_group_product/new.html.twig', [
            'producer' => $producer,
            'form'     => $form->createView(),
        ]);
    }

    /**
     * Editar un producto del catálogo.
     */
    #[Route('/gestion/consumer-group/products/{id}/edit', name: 'consumer_group_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $product->getProducer()->getId()]);
        }

        return $this->render('consumer_group_product/edit.html.twig', [
            'product' => $product,
            'form'    => $form->createView(),
        ]);
    }

    /**
     * Borrar un producto del catálogo. Si está usado en algun pedido (FK RESTRICT),
     * la ficha ofrece desactivarlo en su lugar; aquí el guard lo captura.
     */
    #[Route('/gestion/consumer-group/products/{id}/delete', name: 'consumer_group_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        $producerId = $product->getProducer()->getId();

        if (!$this->isCsrfTokenValid('consumer_group_product_delete_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
        }

        try {
            $em->remove($product);
            $em->flush();
            $this->addFlash('success', 'Producto borrado del catálogo.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'Ese producto se ha usado en algun pedido y no se puede borrar. Desactívalo en su lugar (editándolo).');
        }

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
    }
}
