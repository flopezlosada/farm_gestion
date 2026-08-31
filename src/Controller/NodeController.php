<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\WeeklyBasketGroup;
use App\Form\NodeType;
use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;
use App\Repository\NodeRepository;
use App\Repository\PartnerRepository;
use App\Repository\WeeklyBasketGroupRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\NodeShareCoherence;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD del catálogo de nodos físicos de reparto.
 * Sub-fase 8.8a (2026-05-26). Vista de detalle + gestión de grupos
 * añadidas en el rediseño de reparto.
 */
#[Route('/gestion/node')]
#[IsGranted('ROLE_GESTION_REPARTO')]
class NodeController extends AbstractController
{
    /** Horizonte (en semanas) que se mira hacia adelante al calcular próximos repartos. */
    private const UPCOMING_HORIZON_WEEKS = 16;

    /**
     * Listado de nodos con su próximo reparto calculado y un resumen
     * agregado (stat strip) en cabecera.
     *
     * @param NodeRepository $nodeRepository
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $deliveryDate
     * @return Response
     */
    #[Route('/', name: 'node_index', methods: ['GET'])]
    public function index(
        NodeRepository $nodeRepository,
        BasketRepository $basketRepository,
        NodeDeliveryDate $deliveryDate
    ): Response {
        $nodes = $nodeRepository->findBy([], ['name' => 'ASC']);
        $upcomingBaskets = $this->upcomingBaskets($basketRepository);

        $nodeStats = [];
        $totalGroups = 0;
        $totalActivePartners = 0;
        foreach ($nodes as $node) {
            $groups = $node->getWeeklyBasketGroups()->count();
            $active = 0;
            foreach ($node->getWeeklyBasketGroups() as $group) {
                $active += $this->countActivePartners($group);
            }
            $nodeStats[$node->getId()] = [
                'groups' => $groups,
                'active' => $active,
                'next' => $this->firstDeliveryDate($node, $upcomingBaskets, $deliveryDate),
            ];
            $totalGroups += $groups;
            $totalActivePartners += $active;
        }

        return $this->render('node/index.html.twig', [
            'nodes' => $nodes,
            'node_stats' => $nodeStats,
            'stats' => [
                'nodes' => count($nodes),
                'groups' => $totalGroups,
                'active_partners' => $totalActivePartners,
            ],
        ]);
    }

    /**
     * Ficha del nodo: datos, próximos repartos reales, grupos que cuelgan
     * de él (con su nº de socios activos) y los grupos sin nodo disponibles
     * para engancharle.
     *
     * @param Node $node
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $deliveryDate
     * @param WeeklyBasketGroupRepository $groupRepository
     * @return Response
     */
    #[Route('/{id}', name: 'node_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Node $node,
        BasketRepository $basketRepository,
        NodeDeliveryDate $deliveryDate,
        WeeklyBasketGroupRepository $groupRepository,
        WeeklyBasketGenerator $generator,
        WeeklyBasketRepository $weeklyBasketRepository,
        EggDeliveryResolver $eggResolver,
        PartnerRepository $partnerRepository
    ): Response {
        // Próximos repartos: estimación (proyección sin persistir) de cuánto
        // se va a repartir en cada viernes operativo del nodo, huevos incluidos.
        $upcoming = [];
        foreach ($this->upcomingBaskets($basketRepository) as $basket) {
            $date = $deliveryDate->physicalDateFor($basket, $node);
            if ($date === null) {
                continue;
            }
            $estimate = $this->estimateForNode($node, $basket, $generator, $eggResolver);
            $upcoming[] = [
                'basket' => $basket,
                'date' => $date,
                'socios' => $estimate['socios'],
                'cestas' => $estimate['cestas'],
                'docenas' => $estimate['docenas'],
            ];
            if (count($upcoming) >= 4) {
                break;
            }
        }

        // Repartos anteriores: lo que se estimó repartir en las semanas ya
        // generadas (conteo real de los WeeklyBasket de entonces).
        $past = [];
        foreach ($weeklyBasketRepository->deliveredHistoryForNode($node, 26) as $row) {
            $past[] = [
                'basket' => $row['basket'],
                'date' => $deliveryDate->physicalDateFor($row['basket'], $node) ?? $row['basket']->getDate(),
                'socios' => $row['socios'],
                'cestas' => $row['cestas'],
            ];
        }

        $groupStats = [];
        $totalActive = 0;
        foreach ($node->getWeeklyBasketGroups() as $group) {
            $active = $this->countActivePartners($group);
            $groupStats[] = [
                'group' => $group,
                'active' => $active,
                'total' => $group->getPartners()->count(),
            ];
            $totalActive += $active;
        }

        return $this->render('node/show.html.twig', [
            'node' => $node,
            'upcoming' => $upcoming,
            'past' => $past,
            'group_stats' => $groupStats,
            'active_partners' => $totalActive,
            'unassigned_groups' => $groupRepository->findBy(['node' => null], ['name' => 'ASC']),
            // Candidatxs a recibir el listado: cualquier socix activx con correo.
            // Son cuatrocientas, así que el selector es un desplegable con
            // buscador y no una lista de casillas.
            'sheet_recipient_candidates' => $partnerRepository->findActiveWithEmail(),
        ]);
    }

    /**
     * Estima cuántos socios y cestas repartiría el nodo en un Basket, a
     * partir de la proyección de solo lectura del generador (sin escribir).
     * Filtra las suscripciones candidatas a las del nodo y pondera las
     * cestas por modalidad (solo-huevos 0, compartidas ½, resto 1).
     *
     * @param Node $node
     * @param Basket $basket
     * @param WeeklyBasketGenerator $generator
     * @param EggDeliveryResolver $eggResolver
     * @return array{socios: int, cestas: float, docenas: float}
     */
    private function estimateForNode(Node $node, Basket $basket, WeeklyBasketGenerator $generator, EggDeliveryResolver $eggResolver): array
    {
        $socios = 0;
        $cestas = 0.0;
        $docenas = 0.0;
        foreach ($generator->projectForBasket($basket) as $share) {
            if ($share->getPartner()->getWeeklyBasketGroup()?->getNode()?->getId() !== $node->getId()) {
                continue;
            }
            $socios++;
            $cestas += $this->cestaWeight($share);
            if ($eggResolver->delivers($share, $basket)) {
                $docenas += (float) ($share->getEggAmount()?->getDozens() ?? 0);
            }
        }

        return ['socios' => $socios, 'cestas' => $cestas, 'docenas' => $docenas];
    }

    /**
     * Peso en cestas físicas de una suscripción según su modalidad:
     * solo-huevos (5) no lleva cesta; compartidas (4/6/7) son ½; el resto 1.
     *
     * @param PartnerBasketShare $share
     * @return float
     */
    private function cestaWeight(PartnerBasketShare $share): float
    {
        return match ($share->getBasketShare()?->getId()) {
            5 => 0.0,
            4, 6, 7 => 0.5,
            default => 1.0,
        };
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'node_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $node = new Node();
        $form = $this->createForm(NodeType::class, $node);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($node);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Nodo "%s" creado.', $node->getName()));

            return $this->redirectToRoute('node_index');
        }

        return $this->render('node/new.html.twig', [
            'node' => $node,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Editar el punto arrastra a sus socios, porque la cadencia y la semana que
     * abre están copiadas en cada cesta. Dos comportamientos distintos según qué
     * se toque (ver {@see NodeShareCoherence}):
     *
     *  - Cambiar la CADENCIA de forma que deje cestas fuera se BLOQUEA: el
     *    cambio no se guarda y se listan los socios a corregir primero. A qué
     *    modalidad pasa cada uno es decisión de administración, no del software.
     *  - Cambiar la SEMANA de un punto mensual se PROPAGA: sus socios recogen la
     *    semana que abra el punto, no hay nada que decidir. Se les estampa y se
     *    recolocan los listados ya generados.
     *
     * @param Request $request
     * @param Node $node
     * @param EntityManagerInterface $entityManager
     * @param NodeShareCoherence $coherence
     * @return Response
     */
    #[Route('/{id}/edit', name: 'node_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Node $node,
        EntityManagerInterface $entityManager,
        NodeShareCoherence $coherence,
    ): Response {
        $originalCadence = $node->getCadence();
        $originalWeek = $node->getMonthlyWeek();

        $form = $this->createForm(NodeType::class, $node);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $orphaned = $node->getCadence() !== $originalCadence
                ? $coherence->sharesThatNoLongerFit($node)
                : [];

            if ($orphaned !== []) {
                // Descarta el cambio en memoria: el objeto está gestionado y un
                // flush posterior de cualquier otro punto lo persistiría.
                $entityManager->refresh($node);

                return $this->render('node/edit.html.twig', [
                    'node' => $node,
                    'form' => $this->createForm(NodeType::class, $node)->createView(),
                    'orphaned_shares' => $orphaned,
                    'attempted_cadence' => Node::CADENCE_LABELS[$form->get('cadence')->getData()] ?? null,
                    'monthly_share_count' => count($coherence->monthlySharesOf($node)),
                ]);
            }

            $entityManager->flush();

            $updated = $node->getMonthlyWeek() !== $originalWeek
                ? $coherence->propagateMonthlyWeek($node)
                : [];

            $this->addFlash('success', sprintf('Nodo "%s" actualizado.', $node->getName()));
            if ($updated !== []) {
                $this->addFlash('info', sprintf(
                    'Actualizada la entrega del mes de %d cesta(s) de este punto: %s.',
                    count($updated),
                    implode(', ', array_map(
                        static fn (PartnerBasketShare $s): string => $s->getPartner()?->getNameForDelivery() ?? '?',
                        $updated,
                    )),
                ));
            }

            return $this->redirectToRoute('node_index');
        }

        return $this->render('node/edit.html.twig', [
            'node' => $node,
            'form' => $form->createView(),
            // Aviso previo: cambiar la semana del punto arrastra a estas cestas.
            'monthly_share_count' => count($coherence->monthlySharesOf($node)),
        ]);
    }

    /**
     * Engancha un grupo de recogida existente (sin nodo) a este nodo. Mueve de
     * golpe a TODOS sus socios, así que se comprueba antes que sus cestas caben
     * en el punto: si alguna no, no se engancha nada (ver {@see NodeShareCoherence}).
     *
     * @param Request $request
     * @param Node $node
     * @param WeeklyBasketGroupRepository $groupRepository
     * @param EntityManagerInterface $entityManager
     * @param NodeShareCoherence $coherence
     * @return Response
     */
    #[Route('/{id}/grupos', name: 'node_attach_group', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachGroup(
        Request $request,
        Node $node,
        WeeklyBasketGroupRepository $groupRepository,
        EntityManagerInterface $entityManager,
        NodeShareCoherence $coherence
    ): Response {
        if (!$this->isCsrfTokenValid('attach_group' . $node->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group = $groupRepository->find((int) $request->request->get('group_id'));
        if (!$group instanceof WeeklyBasketGroup) {
            $this->addFlash('error', 'El grupo indicado no existe.');

            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $orphaned = $coherence->groupSharesThatDoNotFit($group, $node);
        if ($orphaned !== []) {
            $this->addFlash('error', sprintf(
                'No se ha asignado "%s" a "%s": %d cesta(s) no se podrían repartir ahí (%s). Cámbiales la modalidad primero.',
                $group->getName(),
                $node->getName(),
                count($orphaned),
                implode(', ', array_map(
                    static fn (PartnerBasketShare $s): string => sprintf(
                        '%s, %s',
                        $s->getPartner()?->getNameForDelivery() ?? '?',
                        $s->getBasketShare()?->getName() ?? '?',
                    ),
                    $orphaned,
                )),
            ));

            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group->setNode($node);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Grupo "%s" asignado a "%s".', $group->getName(), $node->getName()));

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * Desengancha un grupo de este nodo (lo deja sin nodo asignado).
     *
     * @param Request $request
     * @param Node $node
     * @param int $groupId
     * @param WeeklyBasketGroupRepository $groupRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/grupos/{groupId}/quitar', name: 'node_detach_group', methods: ['POST'], requirements: ['id' => '\d+', 'groupId' => '\d+'])]
    public function detachGroup(
        Request $request,
        Node $node,
        int $groupId,
        WeeklyBasketGroupRepository $groupRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('detach_group' . $groupId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $group = $groupRepository->find($groupId);
        if ($group instanceof WeeklyBasketGroup && $group->getNode() === $node) {
            $group->setNode(null);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Grupo "%s" desvinculado del nodo.', $group->getName()));
        }

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * @param Request $request
     * @param Node $node
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'node_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Node $node, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $node->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_index');
        }

        if ($node->getWeeklyBasketGroups()->count() > 0) {
            $this->addFlash('error', sprintf(
                'No se puede borrar "%s": tiene %d grupo(s) asociado(s). Reasígnalos antes.',
                $node->getName(),
                $node->getWeeklyBasketGroups()->count()
            ));

            return $this->redirectToRoute('node_index');
        }

        $entityManager->remove($node);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Nodo "%s" borrado.', $node->getName()));

        return $this->redirectToRoute('node_index');
    }

    /**
     * Baskets (ciclos semanales) desde hoy hasta el horizonte de cálculo.
     *
     * @param BasketRepository $basketRepository
     * @return \App\Entity\Basket[]
     */
    private function upcomingBaskets(BasketRepository $basketRepository): array
    {
        $today = new \DateTimeImmutable('today');

        return $basketRepository->findBetweenDates(
            $today,
            $today->modify(sprintf('+%d weeks', self::UPCOMING_HORIZON_WEEKS))
        );
    }

    /**
     * Primera fecha física de reparto del nodo dentro de los baskets dados,
     * o null si no reparte en el horizonte.
     *
     * @param Node $node
     * @param \App\Entity\Basket[] $baskets Ordenados por fecha ascendente.
     * @param NodeDeliveryDate $deliveryDate
     * @return \DateTimeImmutable|null
     */
    private function firstDeliveryDate(Node $node, array $baskets, NodeDeliveryDate $deliveryDate): ?\DateTimeImmutable
    {
        foreach ($baskets as $basket) {
            $date = $deliveryDate->physicalDateFor($basket, $node);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Añade a alguien a quienes reciben el listado de este nodo.
     *
     * De uno en uno y desde la ficha, no como campo del formulario de edición:
     * son cuatrocientas fichas con correo, y cuatrocientas casillas no se pueden
     * usar. Es el mismo patrón con el que este nodo engancha sus grupos de
     * recogida, y aprovecha el desplegable con buscador.
     *
     * @param Request           $request           Petición con el token y el socix.
     * @param Node              $node              Nodo al que se añade.
     * @param PartnerRepository $partnerRepository Para resolver el socix.
     * @param EntityManagerInterface $entityManager Para persistir.
     */
    #[Route('/{id}/listado/destinatarios', name: 'node_attach_sheet_recipient', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachSheetRecipient(
        Request $request,
        Node $node,
        PartnerRepository $partnerRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('attach_sheet_recipient' . $node->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $partner = $partnerRepository->find((int) $request->request->get('partner_id'));
        if (!$partner instanceof Partner) {
            $this->addFlash('error', 'La persona indicada no existe.');

            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        // Sin correo no hay envío posible: mejor decirlo aquí que asignarla y que
        // el listado la descarte en silencio cada semana.
        if (!$partner->getEmail()) {
            $this->addFlash('error', sprintf('%s no tiene correo en su ficha: no se le puede mandar el listado.', $partner->getNameForDelivery()));

            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $node->addSheetRecipient($partner);
        $entityManager->flush();
        $this->addFlash('success', sprintf('%s recibirá el listado de "%s".', $partner->getNameForDelivery(), $node->getName()));

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * Deja de mandarle a alguien el listado de este nodo.
     *
     * @param Request           $request           Petición con el token.
     * @param Node              $node              Nodo del que se quita.
     * @param int               $partnerId         Socix que deja de recibirlo.
     * @param PartnerRepository $partnerRepository Para resolver el socix.
     * @param EntityManagerInterface $entityManager Para persistir.
     */
    #[Route('/{id}/listado/destinatarios/{partnerId}/quitar', name: 'node_detach_sheet_recipient', methods: ['POST'], requirements: ['id' => '\d+', 'partnerId' => '\d+'])]
    public function detachSheetRecipient(
        Request $request,
        Node $node,
        int $partnerId,
        PartnerRepository $partnerRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('detach_sheet_recipient' . $partnerId, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
        }

        $partner = $partnerRepository->find($partnerId);
        if ($partner instanceof Partner) {
            $node->removeSheetRecipient($partner);
            $entityManager->flush();
            $this->addFlash('success', sprintf('%s ya no recibe el listado de "%s".', $partner->getNameForDelivery(), $node->getName()));
        }

        return $this->redirectToRoute('node_show', ['id' => $node->getId()]);
    }

    /**
     * Cuenta los socios en estado ACTIVO dentro de un grupo de recogida.
     *
     * @param WeeklyBasketGroup $group
     * @return int
     */
    private function countActivePartners(WeeklyBasketGroup $group): int
    {
        $active = 0;
        foreach ($group->getPartners() as $partner) {
            if ($partner->getStatus() === Partner::STATUS_ACTIVO) {
                $active++;
            }
        }

        return $active;
    }
}
