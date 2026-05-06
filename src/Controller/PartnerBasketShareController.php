<?php

namespace App\Controller;

use App\Custom\WeekOfMonth;
use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Form\PartnerBasketShareType;
use App\Repository\PartnerBasketShareRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use App\Controller\AbstractAppController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/gestion/partner/basket/share")]
class PartnerBasketShareController extends AbstractAppController
{
    #[Route("/", name: "partner_basket_share_index", methods: ["GET"])]
    public function index(PartnerBasketShareRepository $partnerBasketShareRepository): Response
    {
        return $this->render('partner_basket_share/index.html.twig', [
            'partner_basket_shares' => $partnerBasketShareRepository->findAll(),
        ]);
    }

    #[Route("/new", name: "partner_basket_share_new", methods: ["GET","POST"])]
    public function new(Request $request): Response
    {
        $partnerBasketShare = new PartnerBasketShare();
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $partnerBasketShare->setEggMonthPrice($partnerBasketShare->getEggAmount()->getMonthPrice());

            $entityManager->persist($partnerBasketShare);
            $entityManager->flush();

            return $this->redirectToRoute('partner_basket_share_index');
        }

        return $this->render('partner_basket_share/new.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "partner_basket_share_show", methods: ["GET"])]
    public function show(PartnerBasketShare $partnerBasketShare): Response
    {
        return $this->render('partner_basket_share/show.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
        ]);
    }

    #[Route("/{id}/edit", name: "partner_basket_share_edit", methods: ["GET","POST"])]
    public function edit(Request $request, PartnerBasketShare $partnerBasketShare): Response
    {
        if ($partnerBasketShare->getStartDate()) {
            $partnerBasketShare->setStartDate($partnerBasketShare->getStartDate()->format('Y-m-d'));
        }

        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $partnerBasketShare->setStartDate(new \DateTime($partnerBasketShare->getStartDate()));
            $values = $request->get('partner_basket_share');
            if (isset($values['isFreeBasket'])) {
                $partnerBasketShare->setMonthPrice(0);
                $partnerBasketShare->setEggMonthPrice(0);
            } else {
                $partnerBasketShare->setMonthPrice($partnerBasketShare->getBasketShare()->getMonthPrice() * $partnerBasketShare->getAmount());
                if ($partnerBasketShare->getEggAmount()) {
                    $partnerBasketShare->setEggMonthPrice($partnerBasketShare->getEggAmount()->getMonthPrice() * $partnerBasketShare->getAmount());
                } else {
                    $partnerBasketShare->setEggMonthPrice(0);
                }
            }
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partnerBasketShare->getPartner()->getId()));
        }

        return $this->render('partner_basket_share/edit.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'entity' => $partnerBasketShare,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "partner_basket_share_delete", methods: ["DELETE"])]
    public function delete(Request $request, PartnerBasketShare $partnerBasketShare): Response
    {
        if ($this->isCsrfTokenValid('delete' . $partnerBasketShare->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($partnerBasketShare);
            $entityManager->flush();
        }

        return $this->redirectToRoute('partner_basket_share_index');
    }


    #[Route("/status/{status}", name: "partner_basket_share_list_status", methods: ["GET"])]
    public function listStatus($status)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $baskets = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findAllByStatus($status);//devuelve las activas

        $total_basket = 0;
        foreach ($baskets as $basket) {
            if ($basket->getBasketShare()->getId() == 1) {
                $total_basket += 1;
            } elseif ($basket->getBasketShare()->getId() == 2) {
                $total_basket += 0.5;
            } elseif ($basket->getBasketShare()->getId() == 3) {
                $total_basket += 0.5;
            } elseif ($basket->getBasketShare()->getId() == 4) {
                $total_basket += 0.25;
            }


        }

        return $this->render('partner_basket_share/basket.html.twig', [
            'baskets' => $baskets,
            'total_basket' => $total_basket
        ]);

    }

    #[Route("/{id}/finalize", name: "partner_basket_share_finalize", methods: ["GET","POST"])]
    public function finalize(Request $request, PartnerBasketShare $partnerBasketShare): Response
    {
        $entityManager = $this->getDoctrine()->getManager();
        $form = $this->createFormBuilder()
            ->add('end_date', TextType::class, array('label' => 'Fecha de finalización', 'attr' => array('class' => 'datepicker form-control')))
            ->add('save', SubmitType::class, ['label' => 'Finalizar cesta'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $values = $request->get('form');
            $end_date = new \DateTime($values['end_date']);
            $partnerBasketShare->setEndDate($end_date);

            ////no se puede desactivar si la fecha de fin es posterior al día de hoy porque no sabemos cuántos viernes hay entre la fecha de registrar el cambio y
            // la fecha real de fin. Entonces este cambio de estado se realiza en la generación del listado

            if ($end_date->format('Y-m-d') < date('Y-m-d')) {
                $partnerBasketShare->setIsActive(0);
            }


            $entityManager->persist($partnerBasketShare);


            /*
             * aquí lo que pretendo es que si se le da de baja después de que se ha generado el listado, se borre el registro de listado
             */
            $monday = date('d F', strtotime(date('Y', strtotime(date('Y-m-d'))) . "W" . str_pad(date('W'), 2, "0", STR_PAD_LEFT)));
            $friday = strtotime("+4 day", strtotime($monday));
            $next_friday = date("Y-m-d", $friday);
            $end_date_format = $end_date->format("Y-m-d");
            if ($end_date_format < $next_friday) {
                $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual
                $current_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket, "partner" => $partnerBasketShare->getPartner()));
                if ($current_weekly_basket) {
                    $entityManager->remove($current_weekly_basket);
                }
            }


            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('partner_basket_share_list_status', array('status' => 1));
        }

        return $this->render('partner_basket_share/finalize.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'form' => $form->createView(),
        ]);
    }


    /**
     * Generar listado semanal
     */
    #[Route("/generate/weekly", name: "partner_basket_share_generate_weekly", methods: ["GET"])]
    public function generateWeekly()
    {
        $entityManager = $this->getDoctrine()->getManager();

        /*
         * lo primero que hay que hacer es desactivar las cestas que han finalizado. Esto sucede porque quien se cambia de tipo de cesta, se crea
         * una cesta nueva que empieza a partir de la fecha que decide, pero como no se sabe cuándo se va a realizar el cambio en la web, no se
         * puede dar de baja la cesta antigua. Por ejemplo si un socio quiere cambiarse de cesta el mes que viene (por ejemplo quedan 3 viernes)
         * y registramos el cambio en la web ahora mismo, quedan aún 3 viernes con la cesta antigua.
         * Igual con las bajas
         * Entonces hacemos una consulta para buscar si hay alguna cesta cuya fecha finalización sea posterior a hoy y cambiamos su estado
         */
        $end_basket = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findFinalized();
        foreach ($end_basket as $weekly_basket) {
            $weekly_basket->setIsActive(0);
            if ($weekly_basket->getBasketShare()->getId() == 4)//cestas compartidas
            {
                $partner_share = $weekly_basket->getPartnet()->getBasketShare();//es el que comparte la cesta con el que se ha quitado
                $weekly_basket->getPartner()->setSharePartnert(null);//quito la relación del que se da de baja
                $partner_share->setSharePartner(null);//quito la relación del segundo
                $entityManager->persist($partner_share);
                $entityManager->persist($weekly_basket->getPartner());
            }
            $entityManager->persist($weekly_basket);
        }

        $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual

        $control_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array("basket" => $basket->getId()));//para ver si ya la he creado, si está en la tabla weekly_basket la cesta actual

        $day_order = WeekOfMonth::dayOfWeekInMonth($basket->getDate());

        if ($control_weekly_basket) {//este control es para crear las cestas en la tabla de cestas semanal

            /*
             *Como ya existe la cesta de esta semana, no hay que volver a crearla, y saco el listado de la tabla ya creada,
             * no necesito hacer la misma consulta de antes, porque además, para los que se quieren cambiar de semana, se les
             * borra el registro de la tabla weekly_basket, y no deben salir. Si lo hago de la otra manera saldrían de nuevo
             */
            $weekly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 1, 'basket' => $basket));//cestas de socios semanales
            $old_half_basket_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 4, 'basket' => $basket));//cestas de socios semanales media cesta
            $biweekly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 2, 'basket' => $basket));//cestas quincenales para esta semana, quitando las que ya recibieron la anterior
            $monthly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 3, 'basket' => $basket));//cestas mensuales para esta semana
            $only_egg_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 5, 'basket' => $basket));//cestas de solo huevos, están incluidas semanales, quincenales y mensuales, se ponen todas juntas en el listado




            $array_all_partners = array_merge($weekly_partners, $biweekly_partners, $monthly_partners, $only_egg_partners);
            //en este caso del if están ya creadas las entradas de weekly basket

            foreach ($array_all_partners as $all_partner) {//este bucle hay que hacerlo en ambos casos porque lo que hace es setear la variable currentbasket de cada socio
                $all_basket_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket->getId(), "partner" => $all_partner->getPartner()->getId()));
                $all_partner->getPartner()->setCurrentBasket($all_basket_partner);

            }

            /**
             * para los de media cesta como hay que asociarlo con el socio que comparte, lo tengo que hacer fuera
             */
            foreach ($old_half_basket_partners as $weekly_partner) {
                if (!$weekly_partner->getPartner()->getIsListed()) {
                    $weekly_basket_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->
                    findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getId()));
                    $weekly_partner->getPartner()->setCurrentBasket($weekly_basket_partner);
                    //echo $weekly_basket_partner->getID() . " | ";
                    $weekly_basket_share_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->
                    findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getSharePartner()->getId()));

                    if ($weekly_basket_share_partner) {
                        //echo $weekly_basket_share_partner->getID() . " <br>";
                        $weekly_partner->getPartner()->getSharePartner()->setCurrentBasket($weekly_basket_share_partner);
                    } else {//es un control para ver si alguno se ha dado de baja su socio de cesta, con el que comparte la media
                        //echo $weekly_partner->getPartner()->getSharePartner()->getId();
                    }


                    //$weekly_partner->getPartner()->setIsListed(1);
                    $weekly_partner->getPartner()->getSharePartner()->setIsListed(1);
                }
            }


        } else {

            $weekly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersByTypeAndCity(1, 1, $basket);//cestas de socios semanales ordenados por ciudad
            $old_half_basket_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersByTypeAndCity(4, 1, $basket);//cestas de socios semanales media cesta
            $biweekly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersBiweeklyAndCity($basket, 2, 1); //cestas quincenales para esta semana, quitando las que ya recibieron la anterior
            $monthly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersMonthlyAndCity($basket, 3, $day_order);//cestas mensuales para esta semana


            $only_egg_weekly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersByTypeAndCity(5, 1, $basket, true);
            $only_egg_biweekly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersBiweeklyAndCity($basket, 5, 1, true); //cestas quincenales para esta semana, quitando las que ya recibieron la anterior
            $only_egg_monthly_partners = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBasketPartnersMonthlyAndCity($basket, 5, $day_order, true);//cestas mensuales para esta semana

            $only_egg_partners = array_merge($only_egg_weekly_partners, $only_egg_biweekly_partners, $only_egg_monthly_partners);
            $array_all_partners = array_merge($weekly_partners, $old_half_basket_partners, $biweekly_partners, $monthly_partners, $only_egg_weekly_partners, $only_egg_biweekly_partners, $only_egg_monthly_partners);
            //aquí se crean las entradas del weekly basket
            foreach ($array_all_partners as $weekly_partner) {
                $weekly_basket = new WeeklyBasket();
                $weekly_basket->setBasket($basket);
                $weekly_basket->setPartner($weekly_partner->getPartner());
                $weekly_basket_status = $entityManager->getRepository(\App\Entity\WeeklyBasketStatus::class)->find(1);
                $weekly_basket->setWeeklyBasketStatus($weekly_basket_status);
                $weekly_basket->setBasketShare($weekly_partner->getBasketShare());
                $weekly_basket->setWeeklyBasketGroup($weekly_partner->getPartner()->getWeeklyBasketGroup());
                $weekly_basket->setAmount($weekly_partner->getAmount());
                $entityManager->persist($weekly_basket);


                $entityManager->flush();
                $weekly_basket_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getId()));
                $weekly_partner->getPartner()->setCurrentBasket($weekly_basket_partner);

            }


        }


        //contando cestas
        $basket_weekly_partners_amount = 0;//es la variable que controla cuántas cestas hay en esta semana. Se hace sumando las cantidades de cestas de cada socio, y por cada tipo de cesta. En este caso son las semanales
        foreach ($weekly_partners as $weekly_partner) {
            $basket_weekly_partners_amount += $weekly_partner->getAmount();
        }

        $basket_biweekly_partners_amount = 0;
        foreach ($biweekly_partners as $biweekly_partner) {
            $basket_biweekly_partners_amount += $biweekly_partner->getAmount();
        }

        $basket_monthly_partners_amount = 0;
        foreach ($monthly_partners as $monthly_partner) {
            $basket_monthly_partners_amount += $monthly_partner->getAmount();
        }

        $basket_old_half_basket_partners_amount = 0;
        foreach ($old_half_basket_partners as $old_half_basket_partner) {
            $basket_old_half_basket_partners_amount += $old_half_basket_partner->getAmount();
        }

        $basket_only_egg_partners_amount = 0;
        foreach ($only_egg_partners as $only_egg_partner) {
            $basket_only_egg_partners_amount += $only_egg_partner->getAmount();
        }
         // -->fin contando cestas








        $entityManager->flush();
        /*$all_partners = $entityManager->getRepository(\App\Entity\Partner::class)->findAll();
        foreach ($all_partners as $partner) {
            $group = $entityManager->getRepository(\App\Entity\WeeklyBasketGroup::class)->find(rand(1, 13));
            $partner->setWeeklyBasketGroup($group);
             $entityManager->persist($partner);

                $entityManager->flush();
        }*/

        $weekly_basket_groups = $entityManager->getRepository(\App\Entity\WeeklyBasketGroup::class)->findAll();

        return $this->render('partner_basket_share/weekly.html.twig', [
            'weekly_partners' => $weekly_partners,
            'biweekly_partners' => $biweekly_partners,
            'old_half_basket_partners' => $old_half_basket_partners,
            'monthly_partners' => $monthly_partners,
            'basket' => $basket,
            'weekly_basket_groups' => $weekly_basket_groups,
            'only_egg_partners' => $only_egg_partners,
            'basket_weekly_partners_amount' => $basket_weekly_partners_amount,
            'basket_biweekly_partners_amount' => $basket_biweekly_partners_amount,
            'basket_monthly_partners_amount' => $basket_monthly_partners_amount,
            'basket_old_half_basket_partners_amount' => $basket_old_half_basket_partners_amount,
            'basket_only_egg_partners_amount' => $basket_only_egg_partners_amount
        ]);
    }

    #[Route("/{basket_id}/{alternative_order}/generate_pdf/basket_weekly", name: "generate_pdf_basket_weekly", methods: ["GET"])]
    public function generatePdf($basket_id, $alternative_order)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $basket = $entityManager->getRepository(\App\Entity\Basket::class)->find($basket_id);


        if ($alternative_order) {

            $array_all_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findAllByGroupNotHalfBasket($basket, 4);

        } else {
            $weekly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 1, 'basket' => $basket, 'weekly_basket_status' => 1));//cestas de socios semanales
            $biweekly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 2, 'basket' => $basket, 'weekly_basket_status' => 1));//cestas quincenales para esta semana, quitando las que ya recibieron la anterior
            $monthly_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 3, 'basket' => $basket, 'weekly_basket_status' => 1));//cestas mensuales para esta semana
            $only_egg_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 5, 'basket' => $basket, 'weekly_basket_status' => 1));//cestas mensuales para esta semana

            $array_all_partners = array_merge($weekly_partners, $biweekly_partners, $monthly_partners, $only_egg_partners);
        }

        $old_half_basket_partners = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array('basket_share' => 4, 'basket' => $basket, 'weekly_basket_status' => 1));//cestas de socios semanales media cesta


        foreach ($array_all_partners as $weekly_partner) {//este bucle hay que hacerlo en ambos casos porque lo que hace es setear la variable currentbasket de cada socio
            $weekly_basket_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getId()));
            $weekly_partner->getPartner()->setCurrentBasket($weekly_basket_partner);
        }

        $total_baskets_amount=0;// es el total de cestas de esta semana
        foreach ($array_all_partners as $weekly_partner)
        {
            $total_baskets_amount+=$weekly_partner->getAmount();
        }
        foreach ($old_half_basket_partners as $old_half_partner)
        {
            $total_baskets_amount+=($old_half_partner->getAmount()/2);
        }


        foreach ($old_half_basket_partners as $weekly_partner) {
            if (!$weekly_partner->getPartner()->getIsListed()) {
                $weekly_basket_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->
                findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getId()));
                $weekly_partner->getPartner()->setCurrentBasket($weekly_basket_partner);
                //echo $weekly_basket_partner->getID() . " | ";
                $weekly_basket_share_partner = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->
                findOneBy(array("basket" => $basket->getId(), "partner" => $weekly_partner->getPartner()->getSharePartner()->getId()));

                if ($weekly_basket_share_partner) {
                    //echo $weekly_basket_share_partner->getID() . " <br>";
                    $weekly_partner->getPartner()->getSharePartner()->setCurrentBasket($weekly_basket_share_partner);
                } else {//es un control para ver si alguno se ha dado de baja su socio de cesta, con el que comparte la media
                    //echo $weekly_partner->getPartner()->getSharePartner()->getId();
                }


                //$weekly_partner->getPartner()->setIsListed(1);
                $weekly_partner->getPartner()->getSharePartner()->setIsListed(1);
            }
        }


        $pdfOptions = new Options();


        // Instantiate Dompdf with our options
        $dompdf = new Dompdf($pdfOptions);

        $weekly_basket_groups = $entityManager->getRepository(\App\Entity\WeeklyBasketGroup::class)->findAll();

        if ($alternative_order) {
            $html = $this->renderView('partner_basket_share/pdf_alternative_weekly.html.twig', [
                'title' => "Welcome to our PDF Test",
                'old_half_basket_partners' => $old_half_basket_partners,
                'basket' => $basket,
                'all_partners' => $array_all_partners,
                'weekly_basket_groups' => $weekly_basket_groups,
                'total_baskets_amount'=>$total_baskets_amount
            ]);
        } else {
            $html = $this->renderView('partner_basket_share/pdf_weekly.html.twig', [
                'title' => "Welcome to our PDF Test",
                'weekly_partners' => $weekly_partners,
                'biweekly_partners' => $biweekly_partners,
                'old_half_basket_partners' => $old_half_basket_partners,
                'only_egg_partners' => $only_egg_partners,
                'monthly_partners' => $monthly_partners,
                'basket' => $basket,
                'weekly_basket_groups' => $weekly_basket_groups,
                'total_baskets_amount'=>$total_baskets_amount
            ]);
        }


        $dompdf->loadHtml($html);

        // (Optional) Setup the paper size and orientation 'portrait' or 'portrait'
        $dompdf->setPaper('A4', 'portrait');

        // Render the HTML as PDF
        $dompdf->render();

        // Output the generated PDF to Browser (force download)
        $dompdf->stream("mypdf.pdf", [
            "Attachment" => true
        ]);
    }


    /**
     * @ParamConverter("weekly_basket_status_id", class=\App\Entity\WeeklyBasketStatus::class)
     * Cambiar estado de la cesta mensual. Esto es para apuntar si lo recibe o no
     */
    #[Route("/{weekly_basket_status_id}/{weekly_basket_id}/change/status/basket_weekly", name: "partner_basket_weekly_change_status", methods: ["GET"])]
    public function changeStatusWeeklyBasket($weekly_basket_id, $weekly_basket_status_id)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $weeklyBasket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->find($weekly_basket_id);
        $weeklyBasketStatus = $entityManager->getRepository(\App\Entity\WeeklyBasketStatus::class)->find($weekly_basket_status_id);

        $weeklyBasket->setWeeklyBasketStatus($weeklyBasketStatus);

        $entityManager->persist($weeklyBasket);
        $entityManager->flush();

        return $this->redirectToRoute('partner_basket_share_generate_weekly');
    }

    /**
     * Es para los socios mensuales si quieren recoger la cesta la semana siguiente a la que le toca.
     * ESto en principio no se puede dejar establecido, por ejemplo, que recoge la 2ª semana de cada mes,
     * vamos a obligar a que all el mundo recoja en la primera semana. Mientras no se cambie esto, sólo
     * permitiremos cambios puntuales a través de esta función, que lo que hace es borrar el registro de recogida
     * de ese socio mensual para que aparezca la semana siguiente.
     * Bueno, al final sí que se puede elegir el número de semana donde se recoge la cesta, así que esto sigue vigente
     * por si alguien quiere hacer un cambio puntual.
     *
     *  Esto también vale para los quincenales que se quieren cambiar de turno, es decir, empezar a reconger en las semanas alternas
     * a las actuales.
     * Lo que hace el sistema es eliminarlo de la tabla weekly_basket de la última cesta y así aparecerá la semana que viene
     * y ya ha cambiado
     *
     */
    #[Route("/{weekly_basket_id}/change/basket_monthly", name: "partner_basket_monthly_change_week", methods: ["GET"])]

    public function nextWeek($weekly_basket_id)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $weeklyBasket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->find($weekly_basket_id);
        $entityManager->remove($weeklyBasket);
        $entityManager->flush();

        return $this->redirectToRoute('partner_basket_share_generate_weekly');
    }


    #[Route("/{weekly_basket_id}/regenerate_weekly_list", name: "regenerate_weekly_list", methods: ["GET"])]
    public function regenerateWeeklyList($weekly_basket_id)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->deleteWeeklyBasket($weekly_basket_id);


        return $this->redirectToRoute('partner_basket_share_generate_weekly');
    }
}
