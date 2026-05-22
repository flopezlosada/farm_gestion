<?php

namespace App\Controller;

use App\Controller\AbstractAppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractAppController
{
    #[Route("/calendar", name: "calendar")]
    public function calendar(): Response
    {
        return $this->render('Default/calendar.html.twig');
    }

    #[Route("/dashboard", name: "dashboard")]
    public function dashboard(): Response
    {
        $em = $this->getDoctrine()->getManager();
        $week_lay = $em->getRepository(\App\Entity\Lay::class)->findWeekLay(10);

        $total_lay_eggs = $em->getRepository(\App\Entity\Lay::class)->findTotalEggs(date('Y'));

        $egg_product = $em->getRepository(\App\Entity\Product::class)->find(1);
        $total_sale_eggs = $em->getRepository(\App\Entity\Sale::class)->findTotalProductYear($egg_product, date('Y'));
        $total_collect_eggs_user = $em->getRepository(\App\Entity\Collect::class)->findByUserYear($this->getUser(), $egg_product, date('Y'));

        $hens_feed_product = $em->getRepository(\App\Entity\Product::class)->find(4);
        $turkey_feed_product = $em->getRepository(\App\Entity\Product::class)->find(7);
        $broiler_feed_product = $em->getRepository(\App\Entity\Product::class)->find(5);
        $wheat = $em->getRepository(\App\Entity\Product::class)->find(13);

        $hens_feed_year = $em->getRepository(\App\Entity\Purchase::class)->findByProductYear($hens_feed_product, date('Y'));
        $turkey_feed_year = $em->getRepository(\App\Entity\Purchase::class)->findByProductYear($turkey_feed_product, date('Y'));
        $broiler_feed_year = $em->getRepository(\App\Entity\Purchase::class)->findByProductYear($broiler_feed_product, date('Y'));
        $wheat_year = $em->getRepository(\App\Entity\Purchase::class)->findByProductYear($wheat, date('Y')); //trigo comprado por año

        $total_chicken = $em->getRepository(\App\Entity\Batch::class)->getTotalCarcassWeight(date('Y'));


        $active_hen_batch = $em->getRepository(\App\Entity\Batch::class)->getActiveBatch(6) ?? []; //lotes de gallina activos
        $active_chicken_batch = $em->getRepository(\App\Entity\Batch::class)->getActiveBatch(2) ?? []; //lotes de pollos activos
        foreach ($active_hen_batch as $batch) {
            $week_batch_lay = $em->getRepository(\App\Entity\Lay::class)->findLayInWeekYear(date('W'), date('Y'), $batch->getId());
            if (!empty($week_batch_lay['total'])) {
                $batch->setWeekLay($week_batch_lay['total']);
            } else {
                $batch->setWeekLay(0);
            }

        }

        /* creando las cestas
        for ($x = 2021; $x <= 2100; $x++) {

            for ($i = 1; $i <= 52; $i++) {
                $basket = new Basket();
                $basket->setWeek($i);
                $monday = date('d F Y', strtotime($x . "W" . str_pad($i, 2, "0", STR_PAD_LEFT)));
                //echo $monday."<br>";
                $friday = strtotime("+4 day", strtotime($monday));
                $basket->setDate(new \DateTime(date("Y-m-d", $friday)));
                $em->persist($basket);

            }
        }
        $em->flush();*/

        /*      $productions=$em->getRepository(\App\Entity\Production::class)->findAll();
              foreach($productions as $production)
              {
                  $basket=$em->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear($production->getProductionDate()->format('Y-m-d'));
                  $production->setBasket($basket);
                  $em->persist($production);
              }
              $em->flush();
      */

        return $this->render('Default/dashboard.html.twig', [
            'week_lay' => array_reverse($week_lay),
            'total_lay_eggs' => $total_lay_eggs,
            'total_sale_eggs' => $total_sale_eggs,
            'total_collect_eggs_user' => $total_collect_eggs_user,
            'hens_feed_year' => $hens_feed_year,
            'turkey_feed_year' => $turkey_feed_year,
            'broiler_feed_year' => $broiler_feed_year,
            'active_hen_batch' => $active_hen_batch,
            'active_chicken_batch' => $active_chicken_batch,
            'wheat_year' => $wheat_year,
            'total_chicken' => $total_chicken,
        ]);
    }

    public function layWeek()
    {
        $em = $this->getDoctrine()->getManager();
        $week_eggs = $em->getRepository(\App\Entity\Lay::class)->findByWeek(date('W'), date('Y'));

        return new Response($week_eggs);
    }

    public function totalCropWorking()
    {
        $em = $this->getDoctrine()->getManager();
        $crop_workings = count($em->getRepository(\App\Entity\CropWorking::class)->findActive());

        return new Response($crop_workings);
    }


    public function totalCompost()
    {
        $em = $this->getDoctrine()->getManager();
        $collection_amount = $em->getRepository(\App\Entity\CompostCollection::class)->findTotalAmountCollection(date('Y'));

        return new Response(number_format($collection_amount / 1000, 1));
    }

    public function totalProductionYear($year = null)
    {
        $em = $this->getDoctrine()->getManager();
        if (!$year) {
            $year = date('Y');
        }
        $production = $em->getRepository(\App\Entity\Production::class)->findByYear($year);

        return new Response($production);
    }

}
