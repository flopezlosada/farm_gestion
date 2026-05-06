<?php

namespace App\Controller;

use App\Entity\WeeklyBasketGroup;
use App\Form\WeeklyBasketGroupType;
use App\Repository\WeeklyBasketGroupRepository;
use App\Controller\AbstractAppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/gestion/weekly/basket/group")]
class WeeklyBasketGroupController extends AbstractAppController
{
    #[Route("/", name: "weekly_basket_group_index", methods: ["GET"])]
    public function index(WeeklyBasketGroupRepository $weeklyBasketGroupRepository): Response
    {
        return $this->render('weekly_basket_group/index.html.twig', [
            'weekly_basket_groups' => $weeklyBasketGroupRepository->findAll(),
        ]);
    }

    #[Route("/new", name: "weekly_basket_group_new", methods: ["GET","POST"])]
    public function new(Request $request): Response
    {
        $weeklyBasketGroup = new WeeklyBasketGroup();
        $form = $this->createForm(WeeklyBasketGroupType::class, $weeklyBasketGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($weeklyBasketGroup);
            $entityManager->flush();

            return $this->redirectToRoute('weekly_basket_group_index');
        }

        return $this->render('weekly_basket_group/new.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "weekly_basket_group_show", methods: ["GET"])]
    public function show(WeeklyBasketGroup $weeklyBasketGroup): Response
    {
        return $this->render('weekly_basket_group/show.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
        ]);
    }

    #[Route("/{id}/edit", name: "weekly_basket_group_edit", methods: ["GET","POST"])]
    public function edit(Request $request, WeeklyBasketGroup $weeklyBasketGroup): Response
    {
        $form = $this->createForm(WeeklyBasketGroupType::class, $weeklyBasketGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('weekly_basket_group_index');
        }

        return $this->render('weekly_basket_group/edit.html.twig', [
            'weekly_basket_group' => $weeklyBasketGroup,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "weekly_basket_group_delete", methods: ["DELETE"])]
    public function delete(Request $request, WeeklyBasketGroup $weeklyBasketGroup): Response
    {
        if ($this->isCsrfTokenValid('delete'.$weeklyBasketGroup->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($weeklyBasketGroup);
            $entityManager->flush();
        }

        return $this->redirectToRoute('weekly_basket_group_index');
    }
}
