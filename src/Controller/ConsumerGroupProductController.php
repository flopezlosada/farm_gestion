<?php

namespace App\Controller;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\Image;
use App\Entity\Producer;
use App\Form\ConsumerGroupProductType;
use App\Form\ImageType;
use App\Repository\ConsumerGroupOrderLineRepository;
use App\Repository\ConsumerGroupRoundItemRepository;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use App\Service\ConsumerGroup\ConsumerGroupStats;
use App\Service\ConsumerGroup\ItemsChangeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
    public function new(Request $request, Producer $producer, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $product = new ConsumerGroupProduct();
        $product->setProducer($producer);
        $product->setSortOrder($producer->getProducts()->count());

        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($product);
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_CREATED, null, $this->getUser(), sprintf('Producto "%s" añadido al catálogo de %s.', $product->getName(), $producer));
            $em->flush();
            $this->addFlash('success', 'Producto añadido al catálogo.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        return $this->render('consumer_group_product/new.html.twig', [
            'producer'     => $producer,
            'form'         => $form->createView(),
        ]);
    }

    /**
     * Ficha del producto: fotos (galería), histórico de precios/consumo por ronda
     * y cifras agregadas. SIN precio destacado a propósito —el precio es de cada
     * RONDA ({@see \App\Entity\ConsumerGroupRoundItem}), no del catálogo—; el
     * histórico ya enseña cómo ha variado ronda a ronda.
     */
    #[Route('/gestion/consumer-group/products/{id}', name: 'consumer_group_product_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ConsumerGroupProduct $product, ConsumerGroupRoundItemRepository $historyRepo, ConsumerGroupStats $stats, EntityManagerInterface $em): Response
    {
        $canEdit = $this->isGranted('ROLE_GESTION_GRUPO_CONSUMO_EDIT');

        return $this->render('consumer_group_product/show.html.twig', [
            'product' => $product,
            'photos'  => $this->photosOf($product, $em),
            'history' => $historyRepo->findHistoryForProduct($product),
            'stats'   => $stats->forProduct($product),
            // La galería la borra/sube quien puede editar; para el resto no
            // se construye el form (mismo criterio que 'can_edit' del twig).
            'photo_form' => $canEdit ? $this->buildPhotoForm($product)->createView() : null,
        ]);
    }

    /**
     * Editar un producto del catálogo.
     */
    #[Route('/gestion/consumer-group/products/{id}/edit', name: 'consumer_group_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupProduct $product, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ConsumerGroupProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_UPDATED, null, $this->getUser(), sprintf('Producto "%s" actualizado.', $product->getName()));
            $em->flush();
            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
        }

        return $this->render('consumer_group_product/edit.html.twig', [
            'product'      => $product,
            'form'         => $form->createView(),
        ]);
    }

    /**
     * Activa/desactiva un producto con un solo click, sin pasar por el
     * formulario completo — mismo patrón que togglePaid/togglePickedUp de
     * ConsumerGroupController.
     */
    #[Route('/gestion/consumer-group/products/{id}/toggle-active', name: 'consumer_group_product_toggle_active', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleActive(
        Request $request,
        ConsumerGroupProduct $product,
        ConsumerGroupEventRecorder $recorder,
        EntityManagerInterface $em,
        ConsumerGroupRoundItemRepository $roundItemRepo,
        ConsumerGroupOrderLineRepository $orderLines,
        ItemsChangeNotifier $itemsChangeNotifier,
    ): Response {
        if (!$this->isCsrfTokenValid('consumer_group_product_toggle_active_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
        }

        $activating = !$product->isActive();

        // Desactivar (no activar) puede dejar sin producto pedidos REALES de
        // socias en rondas todavía ABIERTAS: no se ha entregado nada, así que
        // no hay vuelta atrás si se pierde sin avisar. Se lista y se pide
        // confirmación explícita — mismo criterio que quitar un producto desde
        // "Productos del pedido" (ConsumerGroupController::items()), solo que
        // aquí puede afectar a varias rondas a la vez, no una.
        $affected = [];
        if (!$activating) {
            foreach ($roundItemRepo->findHistoryForProduct($product) as $item) {
                $round = $item->getRound();
                if ($round === null || $round->getStatus() !== ConsumerGroupRound::STATUS_OPEN) {
                    continue;
                }
                $lines = $orderLines->findWithQuantityForItem($item);
                if ($lines !== []) {
                    $affected[] = ['round' => $round, 'item' => $item, 'lines' => $lines];
                }
            }
        }

        if ($affected !== [] && '1' !== $request->request->get('confirm_deactivate')) {
            return $this->render('consumer_group_product/confirm_deactivate.html.twig', [
                'product' => $product,
                'affected' => $affected,
            ]);
        }

        foreach ($affected as $entry) {
            $round = $entry['round'];
            $partners = [];
            foreach ($entry['lines'] as $line) {
                $partner = $line->getOrder()?->getPartner();
                if ($partner !== null) {
                    $partners[$partner->getId()] = $partner;
                }
            }
            $round->removeItem($entry['item']);
            if ($partners !== []) {
                $itemsChangeNotifier->notify(
                    $round,
                    array_values($partners),
                    sprintf('Se ha quitado: %s (producto retirado del catálogo). Revisa tu pedido.', $product->getName()),
                );
            }
        }

        $product->setActive($activating);
        $recorder->record(
            ConsumerGroupEventLog::KIND_PRODUCT_UPDATED,
            null,
            $this->getUser(),
            sprintf('Producto "%s" marcado como %s.', $product->getName(), $activating ? 'activo' : 'inactivo'),
        );
        $em->flush();
        $this->addFlash('success', $activating ? 'Producto activado.' : 'Producto desactivado.');

        return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
    }

    /**
     * Añade una foto a la galería del producto (media polimórfica, como el LAR).
     *
     * VARIAS fotos por producto, no reemplaza: es la ficha la que se enseña a la
     * socia, así que le vale una galería como al LAR, no una miniatura única.
     */
    #[Route('/gestion/consumer-group/products/{id}/photo', name: 'consumer_group_product_photo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadPhoto(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
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

        return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
    }

    /**
     * Quita una foto concreta de la galería del producto. Comprueba que la
     * imagen pertenece de verdad a este producto (defensa ante ids manipulados).
     */
    #[Route('/gestion/consumer-group/products/{id}/photo/{imageId}', name: 'consumer_group_product_photo_delete', methods: ['POST'], requirements: ['id' => '\d+', 'imageId' => '\d+'])]
    public function deletePhoto(Request $request, ConsumerGroupProduct $product, int $imageId, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_product_photo_'.$imageId, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
        }

        $photo = $this->photoOf($product, $imageId, $em);
        if ($photo === null) {
            $this->addFlash('warning', 'La foto no existe o no pertenece a este producto.');

            return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
        }

        $em->remove($photo);
        $em->flush();
        $this->addFlash('success', 'Foto quitada.');

        return $this->redirectToRoute('consumer_group_product_show', ['id' => $product->getId()]);
    }

    /**
     * Todas las fotos de la galería del producto.
     *
     * La media es polimórfica (object_class + foreign_key), así que se consulta
     * por el discriminante del producto y su id.
     *
     * @return Image[]
     */
    private function photosOf(ConsumerGroupProduct $product, EntityManagerInterface $em): array
    {
        return $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $product->getId());
    }

    /**
     * Una foto concreta del producto por id, o null si no existe o pertenece a
     * otro producto.
     */
    private function photoOf(ConsumerGroupProduct $product, int $imageId, EntityManagerInterface $em): ?Image
    {
        foreach ($this->photosOf($product, $em) as $photo) {
            if ($photo->getId() === $imageId) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Form de subida, apuntando al producto.
     */
    private function buildPhotoForm(ConsumerGroupProduct $product, ?Image $image = null): FormInterface
    {
        return $this->createForm(ImageType::class, $image ?? new Image(), [
            'action' => $this->generateUrl('consumer_group_product_photo', ['id' => $product->getId()]),
            'method' => 'POST',
        ]);
    }

    /**
     * Borrar un producto del catálogo. Si está usado en algun pedido (FK RESTRICT),
     * la ficha ofrece desactivarlo en su lugar; aquí el guard lo captura.
     */
    #[Route('/gestion/consumer-group/products/{id}/delete', name: 'consumer_group_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupProduct $product, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $producerId = $product->getProducer()->getId();
        $productName = $product->getName();

        if (!$this->isCsrfTokenValid('consumer_group_product_delete_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
        }

        try {
            // Las fotos van con él: la media polimórfica no tiene clave ajena que
            // las arrastre, así que sin esto quedarían los ficheros y filas
            // apuntando a un producto que ya no existe.
            foreach ($this->photosOf($product, $em) as $photo) {
                $em->remove($photo);
            }

            $em->remove($product);
            $recorder->record(ConsumerGroupEventLog::KIND_PRODUCT_DELETED, null, $this->getUser(), sprintf('Producto "%s" borrado del catálogo.', $productName));
            $em->flush();
            $this->addFlash('success', 'Producto borrado del catálogo.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'Ese producto se ha usado en algun pedido y no se puede borrar. Desactívalo en su lugar (editándolo).');
        }

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
    }
}
