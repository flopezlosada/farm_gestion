<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;

class GraphController extends AbstractController
{
    /**
     * @Route("/graph_egg_week", name="graph_egg_week")
     * @Template()
     */
    public function graphEggWeek()
    {
        $em = $this->getDoctrine()->getManager();
        $year = date("Y");
        $weeks_lay = $em->getRepository('App:Lay')->findWeeksYear($year);
        $daily_lay = array();
        foreach ($weeks_lay as $week) {
            for ($i = 1; $i <= 7; $i++) {
                $daily_lay[$week['week']][$i] = $em->getRepository('App:Lay')->findDailyLayYear($year, $week, $i);
            }
        }


        return array(
            'daily_lay' => $daily_lay,
            'year' => $year
        );
    }

    /**
     * @Route("/graph_average_egg_week", name="graph_average_egg_week")
     * @Template()
     */
    public function graphAverageEggWeek()
    {
        return array();
    }


}
