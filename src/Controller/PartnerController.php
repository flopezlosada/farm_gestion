<?php

namespace App\Controller;

use App\Custom\WeekOfMonth;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Form\PartnerBasketShareType;
use App\Form\PartnerType;
use App\Repository\PartnerRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/gestion/partner")
 */
class PartnerController extends AbstractController
{
    /**
     * @Route("/", name="partner_index", methods={"GET"})
     */
    public function index(PartnerRepository $partnerRepository): Response
    {

        return $this->render('partner/index.html.twig', [
            'partners' => $partnerRepository->findAll(),
            'title' => 'Listado completo de socias y socios',
            'type' => null
        ]);
    }

    /**
     * @Route("/{type}/list/", name="partner_list", methods={"GET"})
     */
    public function list($type)
    {
        $entityManager = $this->getDoctrine()->getManager();

        if ($type == 1) {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findActiveHasNoBasket();
            $title = 'Listado de socias activas sin cesta';
        } else if ($type == 2) {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findBy(array('is_active' => 0));
            $title = 'Listado de socias que están de baja';
        }

        return $this->render('partner/index.html.twig', [
            'partners' => $partners,
            'title' => $title,
            'type' => $type
        ]);

    }

    /**
     * @Route("/evolution", name="partner_evolution", methods={"GET"})
     */
    public function evolution()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $baskets = $entityManager->getRepository(\App\Entity\Basket::class)->findMonthlyBasket(date('Y-m-d'));//devuelve las cestas anteriores a hoy
        $amount_partners = array();
        $amount_baskets=array();
        foreach ($baskets as $basket) {
            $amount = $entityManager->getRepository(\App\Entity\Partner::class)->findAmountPartnersByMonth($basket[0]);
            $amount_partners[] = array($basket, $amount);
            $amount_basket=$entityManager->getRepository(\App\Entity\Partner::class)->findAmountBasketsByMonth($basket[0]);
            $amount_baskets=array($basket,$amount_basket);
        }


        return $this->render('partner/evolution.html.twig', [
            "amount_partners" => $amount_partners,
            "amount_baskets"=>$amount_baskets
        ]);

    }


    /**
     * @Route("/new", name="partner_new", methods={"GET","POST"})
     */
    public function new(Request $request): Response
    {
        $partner = new Partner();
        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $partner->setInscriptionDate(new \DateTime($partner->getInscriptionDate()->format('Y-m-d')));
            $partner->setIsActive(1);

            if ($partner->getRegistrationFormFile()) {
                $partner->setHasFile(1);
            }
            $entityManager->persist($partner);
            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner/new.html.twig', [
            'partner' => $partner,
            'entity' => $partner,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}", name="partner_show", methods={"GET"})
     */
    public function show(Partner $partner): Response
    {

        return $this->render('partner/show.html.twig', [
            'partner' => $partner,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="partner_edit", methods={"GET","POST"})
     */
    public function edit(Request $request, Partner $partner): Response
    {
        $form = $this->createForm(PartnerType::class, $partner);
        $partner->setInscriptionDate($partner->getInscriptionDate()->format('Y-m-d'));

        $form->handleRequest($request);


        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            /*
             * aquí actualizo el grupo del socio para la última cesta si es que ya ha sido creada
             */
            $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual
            $partner_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket->getId(), 'partner' => $partner));//para ver si ya la he creado, si está en la tabla weekly_basket la cesta actual para este socio
            if ($partner_weekly_basket) {
                $partner_weekly_basket->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());
                $entityManager->persist($partner_weekly_basket);
            }


            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner/edit.html.twig', [
            'partner' => $partner,
            'entity' => $partner,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}", name="partner_delete", methods={"DELETE"})
     */
    public function delete(Request $request, Partner $partner): Response
    {
        if ($this->isCsrfTokenValid('delete' . $partner->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($partner);
            $entityManager->flush();
        }

        return $this->redirectToRoute('partner_index');

    }

    /**
     * @Route("/{id}/{type}/family", name="family", methods={"GET"})
     */
    public function family(Request $request, Partner $partner, $type): Response
    {
        $entityManager = $this->getDoctrine()->getManager();
        if ($type == 'add_family') {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findFamiliar($partner);
        } else {
            $baskets = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBy(array('is_active' => 1, 'basket_share' => 4));
            foreach ($baskets as $basket) {
                if (!$basket->getPartner()->getSharePartner()) //solo muestra los que no están ya relacionados
                {
                    $partners[] = $basket->getPartner();
                }
            }
        }


        return $this->render('partner/family.html.twig', [
            'partner' => $partner,
            'partners' => $partners,
            'type' => $type
        ]);

    }

    /**
     * @Route("/{partner1_id}/{partner2_id}/add_family", name="add_family", methods={"GET"})
     */
    public function addFamily(Request $request, $partner1_id, $partner2_id): Response
    {
        $session = new Session();


        if ($partner1_id == $partner2_id) {
            $session->getFlashBag()->add(
                'warning',
                'Has seleccionado la/el misma/o socia/o. Debes seleccionar otra/o'
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }
        $entityManager = $this->getDoctrine()->getManager();
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->addRelative($partner2);
        foreach ($partner2->getPartnerBasketShares() as $share) {
            $entityManager->remove($share);
        }


        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha añadido correctamente la/el familiar'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));

    }

    /**
     * @Route("/{partner1_id}/{partner2_id}/remove_family", name="remove_family", methods={"GET"})
     */
    public function removeFamily(Request $request, $partner1_id, $partner2_id): Response
    {
        $session = new Session();
        $entityManager = $this->getDoctrine()->getManager();
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->removeRelative($partner2);


        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();
        $session->getFlashBag()->add(
            'success',
            'Se ha quitado correctamente la relación'
        );

        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
    }


    /**
     * @Route("/cities", name="select_city")
     * @Template("partner/cities.html.twig")
     */

    public function cities(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $state = $em->getRepository(\App\Entity\State::class)->findById($request->get('state_id'));
        $cities = $em->getRepository(\App\Entity\City::class)->findByState($state);


        return array(
            'cities' => $cities
        );
    }

    /**
     * @Route("/{id}/demote", name="partner_demote", methods={"GET"})
     */
    public function demote(Request $request, Partner $partner): Response
    {
        $entityManager = $this->getDoctrine()->getManager();
        $partner->setIsActive(false);

        $redirect_to_basket = false;

        if ($partner->hasActiveBasket()) {
            $redirect_to_basket = true;
            $basket_id = $partner->getActiveBasket()->getId();
        }
        $entityManager->persist($partner);
        $entityManager->flush();

        if ($redirect_to_basket) {
            return $this->redirectToRoute('partner_basket_share_finalize', array('id' => $basket_id));
        }

        return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));

    }

    /**
     * @Route("/{id}/promote", name="partner_promote", methods={"GET"})
     */
    public function promote(Request $request, Partner $partner): Response
    {
        $entityManager = $this->getDoctrine()->getManager();
        $partner->setIsActive(true);
        $entityManager->persist($partner);
        $entityManager->flush();

        return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));

    }

    /**
     * @Route("/{id}/add_basket", name="partner_add_basket", methods={"GET","POST"})
     */
    public function addBasket(Request $request, Partner $partner): Response
    {
        $partnerBasketShare = new PartnerBasketShare();
        $partnerBasketShare->setPartner($partner);
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $partnerBasketShare->setStartDate(new \DateTime($partnerBasketShare->getStartDate()));
            $partnerBasketShare->setIsActive(true);
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

            if ($partnerBasketShare->getBasketShare()->getId() == 3) {

            } else {
                $partnerBasketShare->setDayMonthOrder(null);
            }
            $entityManager->persist($partnerBasketShare);


            $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual
            if ($partnerBasketShare->getStartDate() <= $basket->getDate()) {
                $control_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array("basket" => $basket->getId()));//para ver si ya la he creado, si está en la tabla weekly_basket la cesta actual
                if ($control_weekly_basket) {//si ya está creada la lista de esta semana, hay que añadir un registro más con el nuevo socio que empieza esta semana
                    $weekly_basket = new WeeklyBasket();
                    $weekly_basket->setBasket($basket);
                    $weekly_basket->setPartner($partner);
                    $weekly_basket->setAmount($partnerBasketShare->getAmount());
                    $weekly_basket_status = $entityManager->getRepository(\App\Entity\WeeklyBasketStatus::class)->find(1);
                    $weekly_basket->setWeeklyBasketStatus($weekly_basket_status);
                    $weekly_basket->setBasketShare($partnerBasketShare->getBasketShare());
                    $entityManager->persist($weekly_basket);
                }
            }

            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner_basket_share/new.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'entity' => $partnerBasketShare,
            'form' => $form->createView(),
            'partner' => $partner
        ]);


    }


    /**
     * @Route("/{partner1_id}/{partner2_id}/add_share_partner", name="add_share_partner", methods={"GET"})
     */
    public function addSharePartner(Request $request, $partner1_id, $partner2_id): Response
    {
        $session = new Session();
        if ($partner1_id == $partner2_id) {
            $session->getFlashBag()->add(
                'warning',
                'Has seleccionado la/el misma/o socia/o. Debes seleccionar otra/o'
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }
        $entityManager = $this->getDoctrine()->getManager();
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);

        $partner1->setSharePartner($partner2);
        $partner2->setSharePartner($partner1);

        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha añadido correctamente la/el compañera/o para compartir cesta'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));


    }

    /**
     * @Route("/{partner1_id}/{partner2_id}/remove_share_partner", name="remove_share_partner", methods={"GET"})
     */
    public function removeSharePartner(Request $request, $partner1_id, $partner2_id): Response
    {
        $session = new Session();

        $entityManager = $this->getDoctrine()->getManager();
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->setSharePartner(null);
        $partner2->setSharePartner(null);

        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha eliminado correctamente la relación'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
    }



    /**
     * @Route("/generate_historical", name="partner_generate_historical", methods={"GET"})
     */
    public function generateHistorical()
    {
        $entityManager = $this->getDoctrine()->getManager();
       $partners=$entityManager->getRepository(\App\Entity\Partner::class)->findAll();

       foreach ($partners as $partner)
       {
          $start_date=$partner->getInscriptionDate();
          if ($partner->getPartnerBasketShares()){}
          //$initial_basket=;
       }





        return $this->render('partner/evolution.html.twig', [

        ]);

    }



}

