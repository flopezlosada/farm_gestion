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
use App\Service\Delivery\CohortChoiceBuilder;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Partner\BasketModalityChanger;
use App\Service\Partner\PartnerShareEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/gestion/partner/basket/share")]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class PartnerBasketShareController extends AbstractController
{
    #[Route("/", name: "partner_basket_share_index", methods: ["GET"])]
    public function index(PartnerBasketShareRepository $partnerBasketShareRepository): Response
    {
        return $this->render('partner_basket_share/index.html.twig', [
            'partner_basket_shares' => $partnerBasketShareRepository->findAll(),
        ]);
    }

    #[Route("/new", name: "partner_basket_share_new", methods: ["GET","POST"])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        PartnerShareEventRecorder $shareEventRecorder,
    ): Response {
        $partnerBasketShare = new PartnerBasketShare();
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $partnerBasketShare->setEggMonthPrice($partnerBasketShare->getEggAmount()->getMonthPrice());

            $entityManager->persist($partnerBasketShare);
            $shareEventRecorder->recordStart($partnerBasketShare);
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
    public function edit(Request $request, PartnerBasketShare $partnerBasketShare, EntityManagerInterface $entityManager, CohortChoiceBuilder $cohortChoiceBuilder): Response
    {
        if ($partnerBasketShare->getStartDate()) {
            $partnerBasketShare->setStartDate($partnerBasketShare->getStartDate()->format('Y-m-d'));
        }

        $cohort = $cohortChoiceBuilder->forPartner($partnerBasketShare->getPartner());
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare, [
            'cohort_choices' => $cohort['cohortChoices'],
            'exclude_weekly_shares' => $cohort['excludeWeeklyShares'],
        ]);
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
            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partnerBasketShare->getPartner()->getId()));
        }

        return $this->render('partner_basket_share/edit.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'entity' => $partnerBasketShare,
            'form' => $form->createView(),
            'node_is_biweekly' => $cohort['nodeIsBiweekly'],
            'node_name' => $cohort['nodeName'],
            'node_dates_label' => $cohort['nodeDatesLabel'],
        ]);
    }

    /**
     * Cambio de modalidad de cesta CON histórico: a diferencia de edit() (que
     * sobrescribe la PBS en sitio, para corregir datos), este flujo parte el
     * histórico — cierra la PBS vigente en la víspera de la fecha efectiva y
     * abre una nueva — y emite un evento BASKET_CHANGE. Es la versión UI del
     * comando app:change-basket-modality.
     *
     * El formulario reusa PartnerBasketShareType sobre una PBS NUEVA precargada
     * con los valores vigentes; su campo start_date se reinterpreta como la
     * "fecha efectiva del cambio". Tras partir el histórico, los listados YA
     * generados (fecha >= efectiva) se reconcilian al patrón nuevo vía
     * WeeklyBasketGenerator::reconcilePartnerFrom (añade donde ahora reparte,
     * quita donde ya no); las semanas sin generar las siembra la generación normal.
     *
     * @param PartnerBasketShare $partnerBasketShare PBS vigente que se cambia (la del enlace).
     */
    #[Route("/{id}/change-modality", name: "partner_basket_share_change_modality", methods: ["GET", "POST"])]
    public function changeModality(
        Request $request,
        PartnerBasketShare $partnerBasketShare,
        BasketModalityChanger $modalityChanger,
        WeeklyBasketGenerator $generator,
        CohortChoiceBuilder $cohortChoiceBuilder,
    ): Response {
        $new = new PartnerBasketShare();
        $new->setPartner($partnerBasketShare->getPartner());
        $new->setBasketShare($partnerBasketShare->getBasketShare());
        $new->setAmount($partnerBasketShare->getAmount());
        $new->setEggAmount($partnerBasketShare->getEggAmount());
        $new->setEggPeriod($partnerBasketShare->getEggPeriod());
        $new->setDeliveryGroup($partnerBasketShare->getDeliveryGroup());
        $new->setDayMonthOrder($partnerBasketShare->getDayMonthOrder());
        $new->setEggDayMonthOrder($partnerBasketShare->getEggDayMonthOrder());
        // start_date queda vacío: admin introduce la fecha EFECTIVA del cambio.

        // Turno de viernes traducido a fechas físicas reales del nodo del socio
        // (ver CohortChoiceBuilder). El turno A/B sólo aplica a quincenales en
        // nodos semanales; en nodos quincenales lo fija el punto y se informa.
        $cohort = $cohortChoiceBuilder->forPartner($partnerBasketShare->getPartner());
        $nodeIsBiweekly = $cohort['nodeIsBiweekly'];
        if ($nodeIsBiweekly) {
            // El turno A/B no aplica a nodos quincenales: el motor lo ignora.
            $new->setDeliveryGroup(null);
        }
        $node = $partnerBasketShare->getPartner()->getWeeklyBasketGroup()?->getNode();

        $form = $this->createForm(PartnerBasketShareType::class, $new, [
            'cohort_choices' => $cohort['cohortChoices'],
            'exclude_weekly_shares' => $cohort['excludeWeeklyShares'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Defensa server: una cesta semanal no cabe en un punto quincenal.
            if ($nodeIsBiweekly && in_array($new->getBasketShare()?->getId(), BasketShare::IDS_WEEKLY, true)) {
                $this->addFlash('warning', sprintf(
                    'No se puede asignar una cesta semanal en %s: ese punto reparte cada dos semanas.',
                    $node->getName(),
                ));
                return $this->redirectToRoute('partner_basket_share_change_modality', ['id' => $partnerBasketShare->getId()]);
            }

            // El turno A/B sólo se conserva en quincenal sobre nodo semanal.
            if ($nodeIsBiweekly || $new->getBasketShare()?->getId() !== BasketShare::ID_BIWEEKLY) {
                $new->setDeliveryGroup(null);
            }

            $effective = new \DateTime($new->getStartDate());

            $values = $request->get('partner_basket_share');
            if (isset($values['isFreeBasket'])) {
                $new->setMonthPrice(0);
                $new->setEggMonthPrice(0);
            } else {
                $new->setMonthPrice($new->getBasketShare()->getMonthPrice() * $new->getAmount());
                $new->setEggMonthPrice($new->getEggAmount() ? $new->getEggAmount()->getMonthPrice() * $new->getAmount() : 0);
            }

            try {
                $modalityChanger->applyChange($new, $effective);
            } catch (\DomainException $e) {
                $this->addFlash('warning', $e->getMessage());
                return $this->redirectToRoute('partner_show', ['id' => $partnerBasketShare->getPartner()->getId()]);
            }

            $reconciled = $generator->reconcilePartnerFrom($new->getPartner(), $effective);
            $this->addFlash('success', sprintf(
                'Cambio de cesta aplicado con histórico (efectivo %s). %d listado(s) ya generado(s) reconciliado(s) al patrón nuevo.',
                $effective->format('d/m/Y'),
                count($reconciled),
            ));

            return $this->redirectToRoute('partner_show', ['id' => $new->getPartner()->getId()]);
        }

        return $this->render('partner_basket_share/change_modality.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'form' => $form->createView(),
            'node_is_biweekly' => $nodeIsBiweekly,
            'node_name' => $cohort['nodeName'],
            'node_dates_label' => $cohort['nodeDatesLabel'],
        ]);
    }

    #[Route("/{id}", name: "partner_basket_share_delete", methods: ["DELETE"])]
    public function delete(Request $request, PartnerBasketShare $partnerBasketShare, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $partnerBasketShare->getId(), $request->request->get('_token'))) {
            $entityManager->remove($partnerBasketShare);
            $entityManager->flush();
        }

        return $this->redirectToRoute('partner_basket_share_index');
    }


    #[Route("/status/{status}", name: "partner_basket_share_list_status", methods: ["GET"])]
    public function listStatus($status, EntityManagerInterface $entityManager)
    {
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
    public function finalize(
        Request $request,
        PartnerBasketShare $partnerBasketShare,
        EntityManagerInterface $entityManager,
        PartnerShareEventRecorder $shareEventRecorder,
        WeeklyBasketGenerator $generator,
    ): Response {
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
                // Histórico: sólo se emite BASKET_END cuando el share queda
                // realmente desactivado. Si el cierre se programa a futuro,
                // el evento lo emitirá WeeklyBasketGenerator::finalizeExpiredShares
                // cuando llegue la fecha, evitando duplicados.
                $shareEventRecorder->recordEnd($partnerBasketShare, $end_date);
            }


            $entityManager->persist($partnerBasketShare);
            $entityManager->flush();

            // Cascada sobre el listado YA generado: re-materializa las entregas del
            // socio en todas las semanas generadas desde hoy — las posteriores al fin
            // desaparecen, las anteriores se conservan. Sustituye al bloque legacy que
            // solo borraba la semana ACTUAL y dejaba huérfanas las demás generadas
            // (lo cazó el invariante L10). Misma cascada que el cambio de modalidad.
            $generator->reconcilePartnerFrom($partnerBasketShare->getPartner(), new \DateTime('today'));

            return $this->redirectToRoute('partner_basket_share_list_status', array('status' => 1));
        }

        return $this->render('partner_basket_share/finalize.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'form' => $form->createView(),
        ]);
    }


    /**
     * Genera (o recupera) el listado de cestas a repartir el viernes de la
     * semana en curso. La lógica vive en WeeklyBasketGenerator; aquí solo
     * orquestamos: buscar el Basket de la semana, delegar, renderizar.
     */
    #[Route("/generate/weekly", name: "partner_basket_share_generate_weekly", methods: ["GET"])]
    public function generateWeekly(WeeklyBasketGenerator $generator, EntityManagerInterface $entityManager): Response
    {
        $basket = $entityManager->getRepository(\App\Entity\Basket::class)
            ->findBasketByWeekYear(date('Y-m-d'));

        if ($basket === false) {
            $this->addFlash('warning', 'No hay cesta creada para la semana en curso. Crea una desde Granja > Composición de cestas.');
            return $this->redirectToRoute('partner_basket_share_index');
        }

        $report = $generator->generateForBasket($basket);

        return $this->render('partner_basket_share/weekly.html.twig', $report->toTemplateContext());
    }


    /**
     * Cambiar estado de la cesta mensual. Esto es para apuntar si lo recibe o no.
     */
    #[Route("/{weekly_basket_status_id}/{weekly_basket_id}/change/status/basket_weekly", name: "partner_basket_weekly_change_status", methods: ["GET"])]
    public function changeStatusWeeklyBasket($weekly_basket_id, $weekly_basket_status_id, EntityManagerInterface $entityManager)
    {
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

    public function nextWeek($weekly_basket_id, EntityManagerInterface $entityManager)
    {
        $weeklyBasket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->find($weekly_basket_id);
        $entityManager->remove($weeklyBasket);
        $entityManager->flush();

        return $this->redirectToRoute('partner_basket_share_generate_weekly');
    }


    #[Route("/{weekly_basket_id}/regenerate_weekly_list", name: "regenerate_weekly_list", methods: ["GET"])]
    public function regenerateWeeklyList($weekly_basket_id, EntityManagerInterface $entityManager)
    {
        $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->deleteWeeklyBasket($weekly_basket_id);


        return $this->redirectToRoute('partner_basket_share_generate_weekly');
    }
}
