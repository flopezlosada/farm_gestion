<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasketGroup;
use App\Form\NodeType;
use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;
use App\Repository\NodeRepository;
use App\Repository\WeeklyBasketGroupRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD del catálogo de nodos físicos de reparto.
 * Sub-fase 8.8a (2026-05-26). Vista de detalle + gestión de grupos
 * añadidas en el rediseño de reparto.
 */
#[Route('/gestion/node')]
#[IsGranted('ROLE_GESTION_REPARTO')]
class NodeController extends AbstractController
{
    /** Horizonte (en semanas) que se mira hacia adelante al calcular próximos repartos. */
    private const UPCOMING_HORIZON_WEEKS = 16;

    /**
     * Listado de nodos con su próximo reparto calculado y un resumen
     * agregado (stat strip) en cabecera.
     *
     * @param NodeRepository $nodeRepository
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $deliveryDate
     * @return Response
     */
    #[Route('/', name: 'node_index', methods: ['GET'])]
    public function index(
        NodeRepository $nodeRepository,
        BasketRepository $basketRepository,
        NodeDeliveryDate $deliveryDate
    ): Response {
        $nodes = $nodeRepository->findBy([], ['name' => 'ASC']);
        $upcomingBaskets = $this->upcomingBaskets($basketRepository);

        $nodeStats = [];
        $totalGroups = 0;
        $totalActivePartners = 0;
        foreach ($nodes as $node) {
            $groups = $node->getWeeklyBasketGroups()->count();
            $active = 0;
            foreach ($node->getWeeklyBasketGroups() as $group) {
                $active += $this->countActivePartners($group);
            }
            $nodeStats[$node->getId()] = [
                'groups' => $groups,
                'active' => $active,
                'next' => $this->firstDeliveryDate($node, $upcomingBaskets, $deliveryDate),
            ];
            $totalGroups += $groups;
            $totalActivePartners += $active;
        }

        return $this->render('node/index.html.twig', [
            'nodes' => $nodes,
            'node_stats' => $nodeStats,
            'stats' => [
                'nodes' => count($nodes),
                'groups' => $totalGroups,
                'active_partners' => $totalActivePartners,
            ],
        ]);
    }

    /**
     * Ficha del nodo: datos, próximos repartos reales, grupos que cuelgan
     * de él (con su nº de socios activos) y los grupos sin nodo disponibles
     * para engancharle.
     *
     * @param Node $node
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $deliveryDate
     * @param WeeklyBasketGroupRepository $groupRepository
     * @return Response
     */
    #[Route('/{id}', name: 'node_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Node $node,
        BasketRepository $basketRepository,
        NodeDeliveryDate $deliveryDate,
        WeeklyBasketGroupRepository $groupRepository,
        WeeklyBasketGenerator $generator,
        WeeklyBasketRepository $weeklyBasketRepository,
        EggDeliveryResolver $eggResolver
    ): Response {
        // Próximos repartos: estimación (proyección sin persistir) de cuánto
        // se va a repartir en cada viernes operativo del nodo, huevos incluidos.
        $upcoming = [];
        foreach ($this->upcomingBaskets($basketRepository) as $basket) {
            $date = $deliveryDate->physicalDateFor($basket, $node);
            if ($date === null) {
                continue;
            }
            $estimate = $this->estimateForNode($node, $basket, $generator, $eggResolver);
            $upcoming[] = [
                'basket' => $basket,
                'date' => $date,
                'socios' => $estimate['socios'],
                'cestas' => $estimate['cestas'],
                'docenas' => $estimate['docenas'],
            ];
            if (count($upcoming) >= 4) {
                break;
            }
        }

        // Repartos anteriores: lo que se estimó repartir en las semanas ya
        // generadas (conteo real de los WeeklyBasket de entonces).
        $past = [];
        foreach ($weeklyBasketRepository->deliveredHistoryForNode($node, 26) as $row) {
            $past[] = [
                'basket' => $row['basket'],
                'date' => $deliveryDate->physicalDateFor($row['basket'], $node) ?? $row['basket']->getDate(),
                'socios' => $row['socios'],
                'cestas' => $row['cestas'],
            ];
        }

        $groupStats = [];
        $totalActive = 0;
        foreach ($node->getWeeklyBasketGroups() as $group) {
            $active = $this->countActivePartners($group);
            $groupStats[] = [
                'group' => $group,
                'active' => $active,
                'total' => $group->getPartners()->count(),
            ];
            $totalActive += $active;
        }

        return $this->render('node/show.html.twig', [
            'node' => $node,
            'upcoming' => $upcoming,
            'past' => $past,
            'group_stats' => $groupStats,
            'active_partners' => $totalActive,
            'unassigned_groups' => $groupRepository->findBy(['node' => null], ['name' => 'ASC']),
        ]);
    }

    /**
     * Estima cuántos socios y cestas repartiría el nodo en un Basket, a
     * partir de la proyección de solo lectura del generador (sin escribir).
     * Filtra las suscripciones candidatas a las del nodo y pondera las
     * cestas por modalidad (solo-huevos 0, compartidas ½, resto 1).
     *
     * @param Node $node
     * @param Basket $basket
     * @param WeeklyBasketGenerator $generator
     * @param EggDeliveryResolver $eggResolver
     * @return array{socios: int, cestas: float, docenas: float}
     */
    private function estimateForNode(Node $node, Basket $basket, WeeklyBasketGenerator $generator, EggDeliveryResolver $eggResolver): array
    {
        $socios = 0;
        $cestas = 0.0;
        $docenas = 0.0;
        foreach ($generator->projectForBasket($basket) as $share) {
            if ($share->getPartner()->getWeeklyBasketGroup()?->getNode()?->getId() !== $node->getId()) {
                continue;
            }
            $socios++;
            $cestas += $this->cestaWeight($share);
            if ($eggResolver->delivers($share, $basket)) {
                $docenas += (float) ($share->getEggAmount()?->getDozens() ?? 0);
            }
        }

        return ['socios' => $socios, 'cestas' => $cestas, 'docenas' => $docenas];
    }

    /**
     * Peso en cestas físicas de una suscripción según su modalidad:
     * solo-huevos (5) no lleva cesta; compartidas (4/6/7) son ½; el resto 1.
     *
     * @param PartnerBasketShare $share
     * @return float
     */
    private function cestaWeight(PartnerBasketShare $share): float
    {
        return match ($share->getBasketShare()?->getId()) {
            5 => 0.0,
            4, 6, 7 => 0.5,
            default => 1.0,
        };
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
     * Engancha un grupo de recogida existente (sin nodo) a este nodo.
     *
     * @param Request $request
     * @param Node $node
     * @param WeeklyBasketGroupRepository $groupRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/grupos', name: 'node_attach_group', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachGroup(
        Request $request,
        Node $node,
        WeeklyBasketGroupRepository $groupRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('attach_group' . $node->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group = $groupRepository->find((int) $request->request->get('group_id'));
        if (!$group instanceof WeeklyBasketGroup) {
            $this->addFlash('error', 'El grupo indicado no existe.');

            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group->setNode($node);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Grupo "%s" asignado a "%s".', $group->getName(), $node->getName()));

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * Desengancha un grupo de este nodo (lo deja sin nodo asignado).
     *
     * @param Request $request
     * @param Node $node
     * @param int $groupId
     * @param WeeklyBasketGroupRepository $groupRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/grupos/{groupId}/quitar', name: 'node_detach_group', methods: ['POST'], requirements: ['id' => '\d+', 'groupId' => '\d+'])]
    public function detachGroup(
        Request $request,
        Node $node,
        int $groupId,
        WeeklyBasketGroupRepository $groupRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('detach_group' . $groupId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group = $groupRepository->find($groupId);
        if ($group instanceof WeeklyBasketGroup && $group->getNode() === $node) {
            $group->setNode(null);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Grupo "%s" desvinculado del nodo.', $group->getName()));
        }

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * @param Request $request
     * @param Node $node
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'node_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    /**
     * Baskets (ciclos semanales) desde hoy hasta el horizonte de cálculo.
     *
     * @param BasketRepository $basketRepository
     * @return \App\Entity\Basket[]
     */
    private function upcomingBaskets(BasketRepository $basketRepository): array
    {
        $today = new \DateTimeImmutable('today');

        return $basketRepository->findBetweenDates(
            $today,
            $today->modify(sprintf('+%d weeks', self::UPCOMING_HORIZON_WEEKS))
        );
    }

    /**
     * Primera fecha física de reparto del nodo dentro de los baskets dados,
     * o null si no reparte en el horizonte.
     *
     * @param Node $node
     * @param \App\Entity\Basket[] $baskets Ordenados por fecha ascendente.
     * @param NodeDeliveryDate $deliveryDate
     * @return \DateTimeImmutable|null
     */
    private function firstDeliveryDate(Node $node, array $baskets, NodeDeliveryDate $deliveryDate): ?\DateTimeImmutable
    {
        foreach ($baskets as $basket) {
            $date = $deliveryDate->physicalDateFor($basket, $node);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Cuenta los socios en estado ACTIVO dentro de un grupo de recogida.
     *
     * @param WeeklyBasketGroup $group
     * @return int
     */
    private function countActivePartners(WeeklyBasketGroup $group): int
    {
        $active = 0;
        foreach ($group->getPartners() as $partner) {
            if ($partner->getStatus() === Partner::STATUS_ACTIVO) {
                $active++;
            }
        }

        return $active;
    }
}
