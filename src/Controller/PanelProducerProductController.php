<?php

namespace App\Controller;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupProduct;
use App\Entity\Image;
use App\Form\ConsumerGroupProductType;
use App\Form\ImageType;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catálogo propio del productor, desde su panel: alta, edición y borrado.
 *
 * Calcado de {@see ConsumerGroupProductController} (gestión), pero acotado al
 * productor logueado en cada acción — «solo lo mío», nunca el id de otro
 * productor por la URL.
 */
#[Route('/panel/producer/products')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_PRODUCER')]
class PanelProducerProductController extends AbstractController
{
    #[Route('', name: 'panel_producer_product_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $producer = $this->getUser()->getProducer();
        $productIds = [];
        foreach ($producer->getProducts() as $product) {
            $productIds[] = $product->getId();
        }

        return $this->render('Panel/producer/product_index.html.twig', [
            'producer' => $producer,
            'photos'   => $em->getRepository(Image::class)
                ->findOneForObjects(ConsumerGroupProduct::OBJECT_CLASS, array_filter($productIds)),
        ]);
    }

    #[Route('/new', name: 'panel_producer_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $producer = $this->getUser()->getProducer();

        $product = new ConsumerGroupProduct();
        $product->setProducer($producer);
        $product->setSortOrder($producer->getProducts()->count());

        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($product);
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_CREATED, null, $this->getUser(), sprintf('Producto "%s" añadido por el productor.', $product->getName()));
            $em->flush();
            $this->addFlash('success', 'Producto añadido a tu catálogo.');

            return $this->redirectToRoute('panel_producer_product_index');
        }

        return $this->render('Panel/producer/product_new.html.twig', [
            'form'        => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'panel_producer_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupProduct $product, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $this->assertOwnProduct($product);

        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_UPDATED, null, $this->getUser(), sprintf('Producto "%s" actualizado por el productor.', $product->getName()));
            $em->flush();
            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('panel_producer_product_index');
        }

        return $this->render('Panel/producer/product_edit.html.twig', [
            'product'     => $product,
            'form'        => $form->createView(),
            'photos'      => $this->photosOf($product, $em),
            'photo_form'  => $this->buildPhotoForm($product)->createView(),
        ]);
    }

    #[Route('/{id}/photo', name: 'panel_producer_product_photo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadPhoto(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        $this->assertOwnProduct($product);

        $image = new Image();
        $form = $this->buildPhotoForm($product, $image);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $image->setObjectClass(ConsumerGroupProduct::OBJECT_CLASS);
            $image->setForeignKey((string) $product->getId());
            $image->setSingle(false);
            if ((string) $image->getTitle() === '') {
                $image->setTitle($product->getName());
            }
            $em->persist($image);
            $em->flush();
            $this->addFlash('success', 'Foto añadida.');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('warning', $error->getMessage());
            }
        }

        return $this->redirectToRoute('panel_producer_product_edit', ['id' => $product->getId()]);
    }

    #[Route('/{id}/photo/{imageId}', name: 'panel_producer_product_photo_delete', methods: ['POST'], requirements: ['id' => '\d+', 'imageId' => '\d+'])]
    public function deletePhoto(Request $request, ConsumerGroupProduct $product, int $imageId, EntityManagerInterface $em): Response
    {
        $this->assertOwnProduct($product);

        if (!$this->isCsrfTokenValid('panel_producer_product_photo_'.$imageId, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('panel_producer_product_edit', ['id' => $product->getId()]);
        }

        $photo = $this->photoOf($product, $imageId, $em);
        if ($photo === null) {
            $this->addFlash('warning', 'La foto no existe o no pertenece a este producto.');

            return $this->redirectToRoute('panel_producer_product_edit', ['id' => $product->getId()]);
        }

        $em->remove($photo);
        $em->flush();
        $this->addFlash('success', 'Foto quitada.');

        return $this->redirectToRoute('panel_producer_product_edit', ['id' => $product->getId()]);
    }

    #[Route('/{id}/delete', name: 'panel_producer_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupProduct $product, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $this->assertOwnProduct($product);
        $productName = $product->getName();

        if (!$this->isCsrfTokenValid('panel_producer_product_delete_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('panel_producer_product_index');
        }

        try {
            foreach ($this->photosOf($product, $em) as $photo) {
                $em->remove($photo);
            }

            $em->remove($product);
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_DELETED, null, $this->getUser(), sprintf('Producto "%s" borrado por el productor.', $productName));
            $em->flush();
            $this->addFlash('success', 'Producto borrado del catálogo.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'Ese producto se ha usado en algún pedido y no se puede borrar. Desactívalo en su lugar (editándolo).');
        }

        return $this->redirectToRoute('panel_producer_product_index');
    }

    /**
     * 404 si el producto no es del productor logueado.
     */
    private function assertOwnProduct(ConsumerGroupProduct $product): void
    {
        $producer = $this->getUser()->getProducer();
        if ($product->getProducer()?->getId() !== $producer?->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @return Image[]
     */
    private function photosOf(ConsumerGroupProduct $product, EntityManagerInterface $em): array
    {
        return $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $product->getId());
    }

    private function photoOf(ConsumerGroupProduct $product, int $imageId, EntityManagerInterface $em): ?Image
    {
        foreach ($this->photosOf($product, $em) as $photo) {
            if ($photo->getId() === $imageId) {
                return $photo;
            }
        }

        return null;
    }

    private function buildPhotoForm(ConsumerGroupProduct $product, ?Image $image = null): FormInterface
    {
        return $this->createForm(ImageType::class, $image ?? new Image(), [
            'action' => $this->generateUrl('panel_producer_product_photo', ['id' => $product->getId()]),
            'method' => 'POST',
        ]);
    }
}
