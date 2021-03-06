<?php

namespace Gallinas\AppBundle\Controller;

use ADesigns\CalendarBundle\Event\CalendarEvent;
use Gallinas\AppBundle\Entity\Basket;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\DateTime;

class DefaultController extends Controller
{


    public function gestionAction()
    {
        return $this->redirect($this->generateUrl('dashboard'));
    }

    /**
     * @Route("/calendar", name="calendar")
     * @Template()
     */
    public function calendarAction()
    {
        return array();
    }


    /**
     * @Route("/event_show/{id}", name="event_show")
     * @Method("GET")
     * @Template()
     */
    public function eventShowAction($id)
    {
        return array();
    }


    /**
     * @Route("/dashboard", name="dashboard")
     * @Template()
     */
    public function dashboardAction()
    {
        $em = $this->getDoctrine()->getManager();
        $week_lay = $em->getRepository('AppBundle:Lay')->findWeekLay(10);

        $total_lay_eggs = $em->getRepository('AppBundle:Lay')->findTotalEggs(date('Y'));

        $egg_product = $em->getRepository("AppBundle:Product")->find(1);
        $total_sale_eggs = $em->getRepository('AppBundle:Sale')->findTotalProductYear($egg_product, date('Y'));
        $total_collect_eggs_user = $em->getRepository('AppBundle:Collect')->findByUserYear($this->getUser(), $egg_product, date('Y'));

        $hens_feed_product = $em->getRepository("AppBundle:Product")->find(4);
        $turkey_feed_product = $em->getRepository("AppBundle:Product")->find(7);
        $broiler_feed_product = $em->getRepository("AppBundle:Product")->find(5);
        $wheat = $em->getRepository("AppBundle:Product")->find(13);

        $hens_feed_year = $em->getRepository("AppBundle:Purchase")->findByProductYear($hens_feed_product, date('Y'));
        $turkey_feed_year = $em->getRepository("AppBundle:Purchase")->findByProductYear($turkey_feed_product, date('Y'));
        $broiler_feed_year = $em->getRepository("AppBundle:Purchase")->findByProductYear($broiler_feed_product, date('Y'));
        $wheat_year = $em->getRepository("AppBundle:Purchase")->findByProductYear($wheat, date('Y')); //trigo comprado por año

        $total_chicken = $em->getRepository("AppBundle:Batch")->getTotalCarcassWeight(date('Y'));


        $active_hen_batch = $em->getRepository('AppBundle:Batch')->getActiveBatch(6); //lotes de gallina activos
        $active_chicken_batch = $em->getRepository('AppBundle:Batch')->getActiveBatch(2); //lotes de pollos activos
        foreach ($active_hen_batch as $batch) {
            $week_batch_lay = $em->getRepository('AppBundle:Lay')->findLayInWeekYear(date('W'), date('Y'), $batch->getId());
            if ($week_batch_lay['total'] > 0) {
                $batch->setWeekLay($week_batch_lay['total']);
            } else {
                $batch->setWeekLay(0);
            }

        }

/* creando las cestas
        for ($i = 1; $i <= 52; $i++) {
            $basket = new Basket();
            $basket->setWeek($i);
            $monday = date('d F Y', strtotime(2018 . "W" . str_pad($i, 2, "0", STR_PAD_LEFT)));
            echo $monday."<br>";
            $friday = strtotime("+4 day", strtotime($monday));
            $basket->setDate(new \DateTime(date("Y-m-d", $friday)));
            $em->persist($basket);

        }
        $em->flush();*/

  /*      $productions=$em->getRepository("AppBundle:Production")->findAll();
        foreach($productions as $production)
        {
            $basket=$em->getRepository("AppBundle:Basket")->findBasketByWeekYear($production->getProductionDate()->format('Y-m-d'));
            $production->setBasket($basket);
            $em->persist($production);
        }
        $em->flush();
*/

        return array(
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
            'total_chicken' => $total_chicken
        );
    }

    public function layWeekAction()
    {
        $em = $this->getDoctrine()->getManager();
        $week_eggs = $em->getRepository('AppBundle:Lay')->findByWeek(date('W'), date('Y'));

        return new Response($week_eggs);
    }

    public function totalCropWorkingAction()
    {
        $em = $this->getDoctrine()->getManager();
        $crop_workings = count($em->getRepository('AppBundle:CropWorking')->findActive());

        return new Response($crop_workings);
    }


    public function totalCompostAction()
    {
        $em = $this->getDoctrine()->getManager();
        $collection_amount = $em->getRepository('AppBundle:CompostCollection')->findTotalAmountCollection(date('Y'));

        return new Response(number_format($collection_amount / 1000, 1));
    }

    public function totalProductionYearAction($year=null)
    {
        $em = $this->getDoctrine()->getManager();
        if (!$year)
        {
            $year=date('Y');
        }
        $production = $em->getRepository('AppBundle:Production')->findByYear($year);

        return new Response($production);
    }

    public function collectWeekUserAction($product_id)
    {
        $em = $this->getDoctrine()->getManager();
        $product = $em->getRepository("AppBundle:Product")->find($product_id);
        $week_product = $em->getRepository('AppBundle:Collect')->findByUserWeek($this->getUser(), $product, date('W'));

        return new Response($week_product);
    }

    public function totalSalesAmountAction()
    {
        $em = $this->getDoctrine()->getManager();
        $amount = $em->getRepository("AppBundle:Sale")->findTotalSalesAmount();

        return new Response($amount);
    }

    public function lastFeedPurchaseAction()
    {
        $em = $this->getDoctrine()->getManager();
        $product = $em->getRepository("AppBundle:Product")->find(4); //pienso para gallinas
        $date = $em->getRepository("AppBundle:Purchase")->findLastFeedDate($product);

        return new Response($date);
    }


    public function warningAction()
    {
        $em = $this->getDoctrine()->getManager();
        $purchaser_warnings = $em->getRepository('AppBundle:Purchaser')->findByEggWarning();
        $recipient_warnings = $em->getRepository('AppBundle:Recipient')->findByEggWarning();

        return $this->render('AppBundle:Default:warnings.html.twig', array(
            'purchaser_warnings' => $purchaser_warnings,
            'recipient_warnings' => $recipient_warnings,
        ));

    }

    public function averageCollectUserAction($product_id, $year)
    {
        $em = $this->getDoctrine()->getManager();
        $product = $em->getRepository("AppBundle:Product")->find($product_id);
        $average = $em->getRepository('AppBundle:Collect')->findByUserAverageCollect($this->getUser(), $product, $year);

        return new Response($average);
    }


    /**
     * @Route("/user_collect/{product_id}/{year}", name="user_collect",defaults={"year"=null} )
     * @Template()
     */
    public function userCollectAction($product_id, $year)
    {
        if (!$year) {
            $year = date("Y");
        }
        $em = $this->getDoctrine()->getManager();
        $product = $em->getRepository("AppBundle:Product")->find($product_id);
        $collects = $em->getRepository("AppBundle:Collect")->findAllByUserProductYear($this->getUser(), $product, $year);


        return array(
            'year' => $year,
            'collects' => $collects
        );
    }



    public function landind_contactAction()
    {
        return $this->render('AppBundle:Default:landing_contact.html.twig', array());
    }
}
