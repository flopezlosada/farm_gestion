<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_GESTION_GRANJA')]
class GraphController extends AbstractController
{
    #[Route("/graph_egg_week", name: "graph_egg_week")]
    public function graphEggWeek(EntityManagerInterface $em): Response
    {
        $year = date("Y");
        $weeks_lay = $em->getRepository(\App\Entity\Lay::class)->findWeeksYear($year);
        $daily_lay = [];
        foreach ($weeks_lay as $week) {
            for ($i = 1; $i <= 7; $i++) {
                $daily_lay[$week['week']][$i] = $em->getRepository(\App\Entity\Lay::class)->findDailyLayYear($year, $week, $i);
            }
        }

        return $this->render('Graph/graphEggWeek.html.twig', [
            'daily_lay' => $daily_lay,
            'year' => $year,
        ]);
    }

    #[Route("/graph_average_egg_week", name: "graph_average_egg_week")]
    public function graphAverageEggWeek(): Response
    {
        // Endpoint sin template propio — devolvía array() bajo @Template legacy.
        // No tiene plantilla `templates/Graph/graphAverageEggWeek.html.twig`, posiblemente código muerto.
        return new Response('');
    }
}
