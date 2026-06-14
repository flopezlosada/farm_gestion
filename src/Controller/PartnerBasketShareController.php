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

    /**
     * Camino legacy de crear una cesta "desde la nada", retirado: creaba el
     * PartnerBasketShare sin pasar por reconcile (cestas fantasma, sin semanas
     * materializadas) y llevaba roto desde el rediseño (la plantilla compartida
     * exige `partner`, que aquí no existía). Administración pidió un único
     * camino de creación de cesta (reunión 2026-06-11, punto 3): "añadir
     * cesta" desde la ficha del socix. La ruta se conserva como redirect para
     * no romper marcadores.
     */
    #[Route("/new", name: "partner_basket_share_new", methods: ["GET","POST"])]
    public function new(): Response
    {
        $this->addFlash('info', 'Las cestas se añaden desde la ficha de cada socix («Añadir cesta»).');

        return $this->redirectToRoute('partner_index');
    }

    #[Route("/{id}", name: "partner_basket_share_show", methods: ["GET"])]
    public function show(PartnerBasketShare $partnerBasketShare): Response
    {
        return $this->render('partner_basket_share/show.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
        ]);
    }

    /**
     * Corrección IN SITU de la cesta (sin histórico, a diferencia de changeModality).
     * Tras guardar, reconcilia las semanas YA generadas al patrón corregido: sin esa
     * cascada, una corrección de huevos (periodo/cantidad/orden) dejaba la piedra
     * con la config vieja hasta la siguiente generación — lo cazaron los invariantes
     * L11/L17 sobre el clon de la batería (caso JOSE del escenario MIRIAM).
     */
    #[Route("/{id}/edit", name: "partner_basket_share_edit", methods: ["GET","POST"])]
    public function edit(Request $request, PartnerBasketShare $partnerBasketShare, EntityManagerInterface $entityManager, CohortChoiceBuilder $cohortChoiceBuilder, WeeklyBasketGenerator $generator): Response
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

            // Cascada sobre el listado YA generado: la corrección debe verse también
            // en las semanas materializadas (misma cascada que changeModality/finalize).
            $reconciled = $generator->reconcilePartnerFrom($partnerBasketShare->getPartner(), new \DateTime('today'));
            if ($reconciled !== []) {
                $this->addFlash('success', sprintf(
                    'Cesta corregida. %d listado(s) ya generado(s) reconciliado(s) al patrón corregido.',
                    count($reconciled),
                ));
            }

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

        // Total en cestas completas equivalentes según el catálogo. Sustituye
        // a los ifs con ids hardcodeados, que contaban la mensual doble (0,5),
        // la semanal compartida a la mitad (0,25) e ignoraban las compartidas
        // quincenal/mensual (6/7) añadidas después.
        $total_basket = round(array_sum(array_map(
            static fn (PartnerBasketShare $basket): float => $basket->getBasketShare()->getCompleteBasketEquivalence(),
            $baskets
        )), 2);

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

            // El campo es TextType (datepicker solo en frontend): con un valor
            // vacío `new \DateTime('')` significaría HOY y finalizaría la cesta
            // en silencio. Validar antes de tocar nada.
            $raw = trim((string) ($values['end_date'] ?? ''));
            try {
                $end_date = $raw !== '' ? new \DateTime($raw) : throw new \InvalidArgumentException();
            } catch (\Exception) {
                $this->addFlash('warning', 'Indica una fecha de fin válida.');

                return $this->render('partner_basket_share/finalize.html.twig', [
                    'partner_basket_share' => $partnerBasketShare,
                    'form' => $form->createView(),
                ]);
            }

            $isPast = $end_date < new \DateTimeImmutable('today');
            $partnerBasketShare->setEndDate($end_date);

            ////no se puede desactivar si la fecha de fin es posterior al día de hoy porque no sabemos cuántos viernes hay entre la fecha de registrar el cambio y
            // la fecha real de fin. Entonces este cambio de estado se realiza en la generación del listado

            if ($isPast) {
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

            // Vuelta a la ficha (origen habitual de la acción) con un flash que
            // distinga cierre inmediato de cierre programado a futuro.
            $this->addFlash('success', $isPast
                ? 'Cesta finalizada: la última entrega fue el ' . $end_date->format('d/m/Y') . '.'
                : 'Fin de cesta programado para el ' . $end_date->format('d/m/Y') . '. La cesta sigue activa hasta entonces.');

            return $this->redirectToRoute('partner_show', ['id' => $partnerBasketShare->getPartner()->getId()]);
        }

        return $this->render('partner_basket_share/finalize.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'form' => $form->createView(),
        ]);
    }


}
