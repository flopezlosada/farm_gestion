<?php

namespace App\Controller;

use App\Entity\WeeklyBasketGroup;
use App\Form\WeeklyBasketGroupType;
use App\Repository\NodeRepository;
use App\Repository\PartnerRepository;
use App\Repository\WeeklyBasketGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/gestion/weekly/basket/group")]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class WeeklyBasketGroupController extends AbstractController
{
    #[Route("/", name: "weekly_basket_group_index", methods: ["GET"])]
    public function index(
        Request $request,
        WeeklyBasketGroupRepository $weeklyBasketGroupRepository,
        PartnerRepository $partnerRepository,
        NodeRepository $nodeRepository,
        PaginatorInterface $paginator
    ): Response {
        // node='none' filtra "sin nodo"; un id numérico filtra por ese nodo.
        $nodeParam = (string) $request->query->get('node', '');
        $filters = [
            'q'    => trim((string) $request->query->get('q', '')) ?: null,
            'node' => $nodeParam === 'none' ? 'none' : (((int) $nodeParam) ?: null),
        ];

        $pagination = $paginator->paginate(
            $weeklyBasketGroupRepository->findFilteredQb($filters)->getQuery(),
            $request->query->getInt('page', 1),
            25
        );

        $activeByGroup = $partnerRepository->countActiveByGroup();

        return $this->render('weekly_basket_group/index.html.twig', [
            'pagination' => $pagination,
            'active_by_group' => $activeByGroup,
            'filters' => $filters,
            'nodes_all' => $nodeRepository->findBy([], ['name' => 'ASC']),
            'stats' => [
                'groups' => $weeklyBasketGroupRepository->count([]),
                'active' => array_sum($activeByGroup),
                'without_node' => $weeklyBasketGroupRepository->count(['node' => null]),
            ],
        ]);
    }

    #[Route("/new", name: "weekly_basket_group_new", methods: ["GET","POST"])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $weeklyBasketGroup = new WeeklyBasketGroup();
        $form = $this->createForm(WeeklyBasketGroupType::class, $weeklyBasketGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($weeklyBasketGroup);
            $entityManager->flush();

            return $this->redirectToRoute('weekly_basket_group_index');
        }

        return $this->render('weekly_basket_group/new.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "weekly_basket_group_show", methods: ["GET"], requirements: ["id" => "\d+"])]
    public function show(WeeklyBasketGroup $weeklyBasketGroup, PartnerRepository $partnerRepository): Response
    {
        $members = $partnerRepository->findBy(
            ['weekly_basket_group' => $weeklyBasketGroup],
            ['surname' => 'ASC', 'name' => 'ASC']
        );

        $active = 0;
        foreach ($members as $member) {
            if ($member->getStatus() === \App\Entity\Partner::STATUS_ACTIVO) {
                $active++;
            }
        }

        return $this->render('weekly_basket_group/show.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
            'members' => $members,
            'active_count' => $active,
            'candidates' => $partnerRepository->findActiveWithoutGroup(),
        ]);
    }

    /**
     * Añade (reasigna) un socio a este grupo de recogida.
     */
    #[Route("/{id}/socios", name: "weekly_basket_group_add_partner", methods: ["POST"], requirements: ["id" => "\d+"])]
    public function addPartner(
        Request $request,
        WeeklyBasketGroup $weeklyBasketGroup,
        PartnerRepository $partnerRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('add_partner' . $weeklyBasketGroup->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('weekly_basket_group_show', ['id' => $weeklyBasketGroup->getId()]);
        }

        $partner = $partnerRepository->find((int) $request->request->get('partner_id'));
        if ($partner === null) {
            $this->addFlash('error', 'El socio indicado no existe.');

            return $this->redirectToRoute('weekly_basket_group_show', ['id' => $weeklyBasketGroup->getId()]);
        }

        $partner->setWeeklyBasketGroup($weeklyBasketGroup);
        $entityManager->flush();
        $this->addFlash('success', sprintf('%s añadidx al grupo "%s".', $partner->getNameForDelivery(), $weeklyBasketGroup->getName()));

        return $this->redirectToRoute('weekly_basket_group_show', ['id' => $weeklyBasketGroup->getId()]);
    }

    /**
     * Quita un socio del grupo (lo deja sin grupo de recogida asignado).
     */
    #[Route("/{id}/socios/{partnerId}/quitar", name: "weekly_basket_group_remove_partner", methods: ["POST"], requirements: ["id" => "\d+", "partnerId" => "\d+"])]
    public function removePartner(
        Request $request,
        WeeklyBasketGroup $weeklyBasketGroup,
        int $partnerId,
        PartnerRepository $partnerRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('remove_partner' . $partnerId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('weekly_basket_group_show', ['id' => $weeklyBasketGroup->getId()]);
        }

        $partner = $partnerRepository->find($partnerId);
        if ($partner !== null && $partner->getWeeklyBasketGroup() === $weeklyBasketGroup) {
            $partner->setWeeklyBasketGroup(null);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s quitadx del grupo.', $partner->getNameForDelivery()));
        }

        return $this->redirectToRoute('weekly_basket_group_show', ['id' => $weeklyBasketGroup->getId()]);
    }

    /**
     * Reasigna el nodo de un grupo desde el propio listado (edición inline).
     * node_id vacío o 0 lo deja sin nodo. Vuelve al listado conservando
     * filtros/página vía referer.
     */
    #[Route("/{id}/nodo", name: "weekly_basket_group_set_node", methods: ["POST"], requirements: ["id" => "\d+"])]
    public function setNode(
        Request $request,
        WeeklyBasketGroup $weeklyBasketGroup,
        NodeRepository $nodeRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid('set_node' . $weeklyBasketGroup->getId(), (string) $request->request->get('_token'))) {
            $nodeId = (int) $request->request->get('node_id');
            $node = $nodeId > 0 ? $nodeRepository->find($nodeId) : null;
            $weeklyBasketGroup->setNode($node);
            $entityManager->flush();
            $this->addFlash('success', sprintf(
                '"%s" → %s.',
                $weeklyBasketGroup->getName(),
                $node ? $node->getName() : 'sin nodo'
            ));
        }

        $referer = (string) $request->headers->get('referer');
        $back = str_contains($referer, '/gestion/weekly/basket/group')
            ? $referer
            : $this->generateUrl('weekly_basket_group_index');

        return $this->redirect($back);
    }

    #[Route("/{id}/edit", name: "weekly_basket_group_edit", methods: ["GET","POST"])]
    public function edit(Request $request, WeeklyBasketGroup $weeklyBasketGroup, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(WeeklyBasketGroupType::class, $weeklyBasketGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('weekly_basket_group_index');
        }

        return $this->render('weekly_basket_group/edit.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "weekly_basket_group_delete", methods: ["DELETE"])]
    public function delete(Request $request, WeeklyBasketGroup $weeklyBasketGroup, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$weeklyBasketGroup->getId(), $request->request->get('_token'))) {
            $entityManager->remove($weeklyBasketGroup);
            $entityManager->flush();
        }

        return $this->redirectToRoute('weekly_basket_group_index');
    }
}
