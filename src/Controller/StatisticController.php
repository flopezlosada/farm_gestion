<?php

namespace App\Controller;

use Symfony\Component\Form\Extension\Core\Type\SubmitType;


use App\Entity\Purchase;
use App\Controller\AbstractAppController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StatisticController extends AbstractAppController
{
    public function hensFeedTotal()
    {
        $em = $this->getDoctrine()->getManager();
        $hens_feed = $em->getRepository(\App\Entity\Product::class)->find(4); //pienso de gallinas

        $feed_purchases = $em->getRepository(\App\Entity\Purchase::class)->findByProduct($hens_feed);

        /**
         * con esta compra virtual, lo que hago es obtener los valores entre la última compra real y el día actual, como
         * si hoy se hubiera hecho otra compra
         */
        $virtual_feed_purchase = new Purchase();
        $virtual_feed_purchase->setAmount(0);
        $virtual_feed_purchase->setProduct($hens_feed);
        $virtual_feed_purchase->setPurchaseDate(date('Y-m-d'));
        $virtual_feed_purchase->setSinglePrice(0);
        $virtual_feed_purchase->setTotalPrice(0);
        $virtual_feed_purchase->setAmount(0);

        //lo añado al array de compras reales
        $feed_purchases[] = $virtual_feed_purchase;

        return $this->render('Statistic/total_hens_feed.html.twig', array(
            'feed_purchases' => $feed_purchases
        ));
    }

    public function hensFeed($last_hens_feed_purchase, $last_but_one_hens_feed_purchase)
    {
        $em = $this->getDoctrine()->getManager();
        $hens_feed = $em->getRepository(\App\Entity\Product::class)->find(4);
        /*$last_hens_feed_purchase = $em->getRepository(\App\Entity\Purchase::class)->findLastFeedPurchase($hens_feed, 0);
        $last_but_one_hens_feed_purchase = $em->getRepository(\App\Entity\Purchase::class)->findLastFeedPurchase($hens_feed, 1);*/

        $product = $em->getRepository(\App\Entity\Product::class)->find(1);

        $users = $em->getRepository(\App\Entity\User::class)->findByRole('ROLE_COOP');
        $total_product_collect_dates = $em->getRepository(\App\Entity\Collect::class)->findCollectDates($product, $last_hens_feed_purchase->getDate(), $last_but_one_hens_feed_purchase->getDate());
        foreach ($users as $user) {
            $consumed_eggs = $em->getRepository(\App\Entity\Collect::class)->findCollectUserDates($user, $product, $last_hens_feed_purchase->getDate(), $last_but_one_hens_feed_purchase->getDate());
            $user->setConsumedProduct($consumed_eggs);

        }

        $egg_sales = $em->getRepository(\App\Entity\Sale::class)->findPeriodSale($product, $last_hens_feed_purchase->getDate(), $last_but_one_hens_feed_purchase->getDate());

        return $this->render('Statistic\hens_feed.html.twig', array(
            'last_hens_feed_purchase' => $last_hens_feed_purchase,
            'last_but_one_hens_feed_purchase' => $last_but_one_hens_feed_purchase,
            'users' => $users,
            'total_product_collect_dates' => $total_product_collect_dates,
            'egg_sales' => $egg_sales
        ));
    }

    public function eggsSale()
    {
        return $this->render('Statistic\eggs_sale.html.twig', array());
    }

    public function eggsSaleYear($year)
    {
        $em = $this->getDoctrine()->getManager();

        $lay_eggs_year = array(); //array que guarda las puestas por mes de cada año

        $months = array(1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'); //array de meses

        foreach ($months as $month => $month_name) {
            $lay_eggs_year[$month] = $em->getRepository(\App\Entity\Lay::class)->getMonthLay($year, $month);
        }


        $product = $em->getRepository(\App\Entity\Product::class)->find(1);
        $unity = $em->getRepository(\App\Entity\Unity::class)->find(1);
        $sale_eggs_year_single = array();
        foreach ($months as $month => $month_name) {
            $sale_eggs_year_single[$month] = $em->getRepository(\App\Entity\Sale::class)->findTotalProductYearMonth($product, $year, $month, $unity);
        }

        $dozen = $em->getRepository(\App\Entity\Unity::class)->find(3);
        $sale_eggs_year_dozen = array();
        foreach ($months as $month => $month_name) {
            $sale_eggs_year_dozen[$month] = $em->getRepository(\App\Entity\Sale::class)->findTotalProductYearMonth($product, $year, $month, $dozen);
        }

        $collect_eggs_year_single = array();
        foreach ($months as $month => $month_name) {
            $collect_eggs_year_single[$month] = $em->getRepository(\App\Entity\Collect::class)->findTotalProductYearMonth($product, $year, $month, $unity);
        }

        $collect_eggs_year_dozen = array();
        foreach ($months as $month => $month_name) {
            $collect_eggs_year_dozen[$month] = $em->getRepository(\App\Entity\Collect::class)->findTotalProductYearMonth($product, $year, $month, $dozen);
        }

        $gift_eggs_year_single = array();
        foreach ($months as $month => $month_name) {
            $gift_eggs_year_single[$month] = $em->getRepository(\App\Entity\Gift::class)->findTotalProductYearMonth($product, $year, $month, $unity);
        }

        $gift_eggs_year_dozen = array();
        foreach ($months as $month => $month_name) {
            $gift_eggs_year_dozen[$month] = $em->getRepository(\App\Entity\Gift::class)->findTotalProductYearMonth($product, $year, $month, $dozen);
        }


        return $this->render('Statistic\eggs_sale_year.html.twig', array(
            'lay_eggs_year' => $lay_eggs_year,
            'total_lay_eggs' => array_sum($lay_eggs_year),
            'months' => $months,
            'sale_eggs_year_dozen' => $sale_eggs_year_dozen,
            'total_eggs_dozen' => array_sum($sale_eggs_year_dozen),
            'sale_eggs_year_single' => $sale_eggs_year_single,
            'total_eggs_single' => array_sum($sale_eggs_year_single),
            'collect_eggs_year_dozen' => $collect_eggs_year_dozen,
            'collect_eggs_year_single' => $collect_eggs_year_single,
            'total_collect_single' => array_sum($collect_eggs_year_single),
            'total_collect_dozen' => array_sum($collect_eggs_year_dozen),
            'gift_eggs_year_dozen' => $gift_eggs_year_dozen,
            'gift_eggs_year_single' => $gift_eggs_year_single,
            'total_gift_single' => array_sum($gift_eggs_year_single),
            'total_gift_dozen' => array_sum($gift_eggs_year_dozen),
            'year' => $year
        ));
    }

}
