<?php

namespace App\Controller;

use App\Entity\ConsumerGroupProduct;
use App\Entity\Image;
use App\Entity\Producer;
use App\Form\ProducerType;
use App\Repository\ConsumerGroupEventLogRepository;
use App\Repository\ConsumerGroupRoundRepository;
use App\Repository\ProducerRepository;
use App\Security\MagicLinkMailer;
use App\Security\ProducerUserProvisioner;
use App\Service\ConsumerGroup\ConsumerGroupStats;
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
    public function show(Producer $producer, ProducerUserProvisioner $provisioner, ConsumerGroupStats $stats, ConsumerGroupEventLogRepository $events, ConsumerGroupRoundRepository $rounds, EntityManagerInterface $em): Response
    {
        // Las fotos del catálogo, en una consulta: la tabla las pinta por fila y
        // preguntar una vez por producto sería un N+1 con veinte referencias.
        $productIds = [];
        foreach ($producer->getProducts() as $product) {
            $productIds[] = $product->getId();
        }

        // Sólo tiene sentido ofrecer acceso a un productor autogestionado: el
        // que va vía comisión no lleva panel propio.
        $accessUser = $producer->isSelfManaged() ? $provisioner->userFor($producer) : null;

        return $this->render('producer/show.html.twig', [
            'producer' => $producer,
            'stats' => $stats->forProducer($producer),
            'by_round' => $stats->byRoundForProducer($producer),
            'by_product' => $stats->byProductForProducer($producer),
            'by_partner' => $stats->byPartnerForProducer($producer),
            'rounds' => $rounds->findAllForProducer($producer),
            'photos' => $em->getRepository(Image::class)
                ->findOneForObjects(ConsumerGroupProduct::OBJECT_CLASS, array_filter($productIds)),
            'access_user' => $accessUser,
            // Lo que ha hecho el productor con SU propia cuenta: accesos y
            // cambios en su catálogo. Lo que toca la comisión sobre este
            // productor no sale aquí (ver ficha de cada ronda para eso).
            'producer_activity' => $accessUser !== null ? $events->findByActor($accessUser) : [],
        ]);
    }

    /**
     * Da acceso de login al productor con el email de su ficha (reusa cuenta
     * existente o crea una) y le envía el enlace de acceso.
     */
    #[Route('/{id}/access/grant', name: 'consumer_group_producer_access_grant', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function grantAccess(Request $request, Producer $producer, ProducerUserProvisioner $provisioner, MagicLinkMailer $magicLinkMailer): Response
    {
        if (!$this->isCsrfTokenValid('producer_access'.$producer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        try {
            $user = $provisioner->grantAccess($producer);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        if ($magicLinkMailer->send($user)) {
            $this->addFlash('success', sprintf('Acceso concedido a %s. Le hemos enviado un enlace para entrar.', $user->getEmail()));
        } else {
            $this->addFlash('warning', sprintf('Acceso concedido a %s, pero no se pudo enviar el enlace. Reenvíalo desde su ficha.', $user->getEmail()));
        }

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
    }

    /**
     * Reenvía el enlace de acceso a la cuenta del productor.
     */
    #[Route('/{id}/access/resend', name: 'consumer_group_producer_access_resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendAccess(Request $request, Producer $producer, ProducerUserProvisioner $provisioner, MagicLinkMailer $magicLinkMailer): Response
    {
        if (!$this->isCsrfTokenValid('producer_access'.$producer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        $user = $provisioner->userFor($producer);
        if ($user === null) {
            $this->addFlash('warning', 'Este productor aún no tiene cuenta de acceso. Dale acceso primero.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        if ($magicLinkMailer->send($user)) {
            $this->addFlash('success', sprintf('Enlace de acceso reenviado a %s.', $user->getEmail()));
        } else {
            $this->addFlash('error', 'No se pudo enviar el enlace. Revisa la configuración de correo.');
        }

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
    }

    /**
     * Retira el acceso de login del productor (desvincula su cuenta; si era sólo
     * de productor, la deshabilita).
     */
    #[Route('/{id}/access/revoke', name: 'consumer_group_producer_access_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revokeAccess(Request $request, Producer $producer, ProducerUserProvisioner $provisioner): Response
    {
        if (!$this->isCsrfTokenValid('producer_access'.$producer->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
        }

        $provisioner->revokeAccess($producer);
        $this->addFlash('success', sprintf('Acceso retirado a «%s».', $producer->getName()));

        return $this->redirectToRoute('consumer_group_producer_show', ['id' => $producer->getId()]);
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
