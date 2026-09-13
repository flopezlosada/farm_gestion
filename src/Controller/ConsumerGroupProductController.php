<?php

namespace App\Controller;

use App\Entity\ConsumerGroupProduct;
use App\Entity\Image;
use App\Entity\Producer;
use App\Form\ConsumerGroupProductType;
use App\Form\ImageType;
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
            'product'    => $product,
            'form'       => $form->createView(),
            'photo'      => $this->photoOf($product, $em),
            'photo_form' => $this->buildPhotoForm($product)->createView(),
        ]);
    }

    /**
     * Sube la foto del producto (media polimórfica, como el LAR).
     *
     * UNA SOLA FOTO por producto: subir otra reemplaza la anterior. Es una ficha
     * de catálogo, no una galería —lo que hace falta es reconocer el bote de un
     * vistazo—, y con varias habría que decidir cuál manda en cada pantalla.
     */
    #[Route('/gestion/consumer-group/products/{id}/photo', name: 'consumer_group_product_photo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadPhoto(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        $image = new Image();
        $form = $this->buildPhotoForm($product, $image);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Fuera la anterior ANTES de guardar la nueva: si no, quedan las dos
            // en la carpeta y la pantalla elige una al azar.
            $previous = $this->photoOf($product, $em);
            if ($previous !== null) {
                $em->remove($previous);
            }

            $image->setObjectClass(ConsumerGroupProduct::OBJECT_CLASS);
            $image->setForeignKey((string) $product->getId());
            $image->setSingle(true);
            if ((string) $image->getTitle() === '') {
                $image->setTitle($product->getName());
            }
            $em->persist($image);
            $em->flush();
            $this->addFlash('success', 'Foto del producto guardada.');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('warning', $error->getMessage());
            }
        }

        return $this->redirectToRoute('consumer_group_product_edit', ['id' => $product->getId()]);
    }

    /**
     * Quita la foto del producto.
     */
    #[Route('/gestion/consumer-group/products/{id}/photo/delete', name: 'consumer_group_product_photo_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePhoto(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_product_photo_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_product_edit', ['id' => $product->getId()]);
        }

        $photo = $this->photoOf($product, $em);
        if ($photo !== null) {
            $em->remove($photo);
            $em->flush();
            $this->addFlash('success', 'Foto quitada.');
        }

        return $this->redirectToRoute('consumer_group_product_edit', ['id' => $product->getId()]);
    }

    /**
     * La foto de un producto, o null si no tiene.
     *
     * La media es polimórfica (object_class + foreign_key), así que se consulta
     * por el discriminante del producto y su id.
     */
    private function photoOf(ConsumerGroupProduct $product, EntityManagerInterface $em): ?Image
    {
        $photos = $em->getRepository(Image::class)
            ->findForObject(ConsumerGroupProduct::OBJECT_CLASS, $product->getId());

        return $photos[0] ?? null;
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
    public function delete(Request $request, ConsumerGroupProduct $product, EntityManagerInterface $em): Response
    {
        $producerId = $product->getProducer()->getId();

        if (!$this->isCsrfTokenValid('consumer_group_product_delete_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
        }

        try {
            // La foto va con él: la media polimórfica no tiene clave ajena que la
            // arrastre, así que sin esto quedaría el fichero y una fila apuntando
            // a un producto que ya no existe.
            $photo = $this->photoOf($product, $em);
            if ($photo !== null) {
                $em->remove($photo);
            }

            $em->remove($product);
            $em->flush();
            $this->addFlash('success', 'Producto borrado del catálogo.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'Ese producto se ha usado en algun pedido y no se puede borrar. Desactívalo en su lugar (editándolo).');
        }

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producerId]);
    }
}
