<?php

namespace App\Controller;

use App\Entity\Helper;
use App\Entity\HelperBasketSkip;
use App\Entity\Node;
use App\Entity\Stay;
use App\Form\HelperType;
use App\Repository\BasketRepository;
use App\Repository\HelperBasketSkipRepository;
use App\Repository\HelperRepository;
use App\Repository\HelperSourceRepository;
use App\Repository\NodeRepository;
use App\Repository\StayRepository;
use App\Service\Delivery\HelperDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de los voluntarios de acogida del albergue ({@see Helper}). Las estancias
 * de cada uno se gestionan desde su ficha, en {@see StayController}.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
#[Route('/gestion/albergue/helpers')]
#[IsGranted('ROLE_GESTION_ALBERGUE')]
class HelperController extends AbstractController
{
    /**
     * Listado filtrable de voluntarios (por texto y procedencia).
     *
     * @param Request $request
     * @param HelperRepository $helperRepository
     * @param HelperSourceRepository $sourceRepository
     * @return Response
     */
    #[Route('/', name: 'helper_index', methods: ['GET'])]
    public function index(
        Request $request,
        HelperRepository $helperRepository,
        HelperSourceRepository $sourceRepository,
        StayRepository $stayRepository,
    ): Response {
        $term = trim((string) $request->query->get('q', ''));
        $sourceId = $request->query->get('source');
        $sourceId = ($sourceId === null || $sourceId === '') ? null : (int) $sourceId;
        // Por defecto (sin parámetro) se filtra por el AÑO ACTUAL; "Todos los
        // años" se elige explícitamente (radio con value="" → cadena vacía).
        $yearParam = $request->query->get('year');
        $year = match (true) {
            $yearParam === null => (int) date('Y'),
            $yearParam === '', $yearParam === 'all' => null,
            default => (int) $yearParam,
        };
        $sort = (string) $request->query->get('sort', 'name');
        $dir = $request->query->get('dir') === 'desc' ? 'desc' : 'asc';

        $today = new \DateTimeImmutable('today');

        // Filtro por año: quien tenga alguna estancia que toque ese año natural.
        $from = $year !== null ? new \DateTimeImmutable(sprintf('%04d-01-01', $year)) : null;
        $to = $year !== null ? new \DateTimeImmutable(sprintf('%04d-12-31', $year)) : null;

        // Cada fila lleva su estancia "relevante" (la actual; si no, la próxima;
        // si no, la última pasada), de donde salen las fechas y el orden.
        $rows = [];
        foreach ($helperRepository->search($term, $sourceId) as $helper) {
            if ($year !== null && !$this->hasStayInRange($helper, $from, $to)) {
                continue;
            }
            $rows[] = ['helper' => $helper, 'stay' => $this->primaryStay($helper, $today)];
        }
        $rows = $this->sortRows($rows, $sort, $dir);

        return $this->render('helper/index.html.twig', [
            'rows' => $rows,
            'sources' => $sourceRepository->findBy([], ['name' => 'ASC']),
            'years' => $stayRepository->activeYears(),
            'q' => $term,
            'selected_source' => $sourceId,
            'selected_year' => $year,
            'sort' => $sort,
            'dir' => $dir,
            'stats' => [
                'total' => $helperRepository->count([]),
                'current' => count($stayRepository->findOverlapping($today, $today->modify('+1 day'), [Stay::STATUS_CONFIRMED])),
                'arrivals' => count($stayRepository->findArrivalsBetween($today, $today->modify('+30 days'))),
            ],
        ]);
    }

    /**
     * Estancia "relevante" de un voluntario para el listado: la que esté en
     * curso; si no hay, la próxima por llegar; si tampoco, la última pasada.
     * Ignora las canceladas. Null si no tiene ninguna.
     *
     * @param Helper $helper
     * @param \DateTimeImmutable $today
     * @return \App\Entity\Stay|null
     */
    private function primaryStay(Helper $helper, \DateTimeImmutable $today): ?Stay
    {
        $current = null;
        $upcoming = null;
        $past = null;
        foreach ($helper->getStays() as $stay) {
            if ($stay->getStatus() === Stay::STATUS_CANCELLED) {
                continue;
            }
            $arrival = $stay->getArrivalDate();
            $departure = $stay->getDepartureDate();
            if ($arrival <= $today && $today < $departure) {
                $current = $stay;
            } elseif ($arrival > $today) {
                if ($upcoming === null || $arrival < $upcoming->getArrivalDate()) {
                    $upcoming = $stay;
                }
            } elseif ($past === null || $departure > $past->getDepartureDate()) {
                $past = $stay;
            }
        }

        return $current ?? $upcoming ?? $past;
    }

    /**
     * ¿El voluntario tiene alguna estancia (no cancelada) que solape el rango
     * [$from, $hasta] (días inclusive; cualquiera de los dos extremos puede ser
     * null = sin tope por ese lado)?
     *
     * @param Helper $helper
     * @param \DateTimeImmutable|null $from
     * @param \DateTimeImmutable|null $to
     * @return bool
     */
    private function hasStayInRange(Helper $helper, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): bool
    {
        $toExclusive = $to?->modify('+1 day');
        foreach ($helper->getStays() as $stay) {
            if ($stay->getStatus() === Stay::STATUS_CANCELLED) {
                continue;
            }
            if ($from !== null && $stay->getDepartureDate() <= $from) {
                continue;
            }
            if ($toExclusive !== null && $stay->getArrivalDate() >= $toExclusive) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Ordena las filas por la columna pedida. Las fechas/null van al final en
     * ascendente.
     *
     * @param array<int, array{helper: Helper, stay: \App\Entity\Stay|null}> $rows
     * @param string $sort
     * @param string $dir
     * @return array<int, array{helper: Helper, stay: \App\Entity\Stay|null}>
     */
    private function sortRows(array $rows, string $sort, string $dir): array
    {
        usort($rows, fn (array $x, array $y) => match ($sort) {
            'source' => strcasecmp((string) $x['helper']->getSource()?->getName(), (string) $y['helper']->getSource()?->getName()),
            'country' => strcasecmp((string) $x['helper']->getCountry(), (string) $y['helper']->getCountry()),
            'arrival' => $this->compareDates($x['stay']?->getArrivalDate(), $y['stay']?->getArrivalDate()),
            'departure' => $this->compareDates($x['stay']?->getDepartureDate(), $y['stay']?->getDepartureDate()),
            default => strcasecmp((string) $x['helper']->getName(), (string) $y['helper']->getName()),
        });

        return $dir === 'desc' ? array_reverse($rows) : $rows;
    }

    /**
     * Compara dos fechas dejando los null al final (en ascendente).
     *
     * @param \DateTimeImmutable|null $a
     * @param \DateTimeImmutable|null $b
     * @return int
     */
    private function compareDates(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return $a <=> $b;
    }

    /**
     * Ficha del voluntario: sus datos y el historial de estancias.
     *
     * @param Helper $helper
     * @return Response
     */
    #[Route('/{id}', name: 'helper_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Helper $helper): Response
    {
        // Estancias de más reciente a más antigua (por llegada).
        $stays = $helper->getStays()->toArray();
        usort($stays, fn ($a, $b) => $b->getArrivalDate() <=> $a->getArrivalDate());

        return $this->render('helper/show.html.twig', [
            'helper' => $helper,
            'stays' => $stays,
        ]);
    }

    /**
     * Calendario de recogida del voluntario: las semanas en que su nodo reparte
     * durante sus estancias confirmadas, con un interruptor recoge/no-recoge por
     * semana. Como la cesta es semanal no hay cambio de fecha posible: lo único
     * editable es saltar la recogida ({@see HelperBasketSkip}).
     *
     * @param Helper $helper
     * @param NodeRepository $nodeRepository
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $nodeDeliveryDate
     * @param HelperBasketSkipRepository $skipRepository
     * @return Response
     */
    #[Route('/{id}/calendario', name: 'helper_basket_calendar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function basketCalendar(
        Helper $helper,
        NodeRepository $nodeRepository,
        BasketRepository $basketRepository,
        NodeDeliveryDate $nodeDeliveryDate,
        HelperBasketSkipRepository $skipRepository,
    ): Response {
        $node = $this->resolveBasketNode($helper, $nodeRepository);

        $weeks = [];
        if ($helper->isBasketActive() && $node !== null) {
            $skipped = $skipRepository->skippedDatesForHelper($helper);
            $today = new \DateTimeImmutable('today');

            // Por cada estancia confirmada, las fechas físicas de reparto del nodo
            // dentro de [llegada, salida). Se itera una ventana de Baskets algo más
            // ancha (±7 días) porque la fecha física del nodo puede caer en otro día
            // de la semana que el viernes-ciclo; luego se recorta al rango real.
            foreach ($helper->getStays() as $stay) {
                if ($stay->getStatus() !== Stay::STATUS_CONFIRMED) {
                    continue;
                }
                $arrival = $stay->getArrivalDate();
                $departure = $stay->getDepartureDate();
                foreach ($basketRepository->findBetweenDates($arrival->modify('-7 days'), $departure->modify('+7 days')) as $basket) {
                    $physical = $nodeDeliveryDate->physicalDateFor($basket, $node);
                    if ($physical === null || $physical < $arrival || $physical >= $departure) {
                        continue;
                    }
                    $key = $physical->format('Y-m-d');
                    $weeks[$key] = [
                        'date' => $physical,
                        'skipped' => isset($skipped[$key]),
                        'past' => $physical < $today,
                    ];
                }
            }
            ksort($weeks);
        }

        return $this->render('helper/basket_calendar.html.twig', [
            'helper' => $helper,
            'node' => $node,
            'weeks' => array_values($weeks),
        ]);
    }

    /**
     * Marca/desmarca el "no recoge" de un voluntario en una semana concreta. Crea
     * el {@see HelperBasketSkip} si no existía (no recoge) o lo borra si existía
     * (vuelve a recoger). La escritura la protege el access_control de albergue
     * (POST → ROLE_GESTION_ALBERGUE_EDIT).
     *
     * @param Request $request
     * @param Helper $helper
     * @param HelperBasketSkipRepository $skipRepository
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/calendario/skip', name: 'helper_basket_calendar_skip', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function basketCalendarSkip(
        Request $request,
        Helper $helper,
        HelperBasketSkipRepository $skipRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('helper_basket_skip', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('helper_basket_calendar', ['id' => $helper->getId()]);
        }

        $raw = (string) $request->request->get('date');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false) {
            $this->addFlash('error', 'Fecha no válida.');

            return $this->redirectToRoute('helper_basket_calendar', ['id' => $helper->getId()]);
        }

        $existing = $skipRepository->findOneByHelperAndDate($helper, $date);
        if ($existing !== null) {
            $entityManager->remove($existing);
            $this->addFlash('success', sprintf('%s vuelve a recoger el %s.', $helper->getName(), $date->format('d/m/Y')));
        } else {
            $entityManager->persist(new HelperBasketSkip($helper, $date));
            $this->addFlash('success', sprintf('%s no recoge el %s.', $helper->getName(), $date->format('d/m/Y')));
        }
        $entityManager->flush();

        return $this->redirectToRoute('helper_basket_calendar', ['id' => $helper->getId()]);
    }

    /**
     * Nodo de recogida efectivo del voluntario: el suyo asignado, o Torremocha
     * por defecto cuando no tiene ninguno (mismo criterio que
     * {@see HelperDeliveryResolver}). Null sólo si ni siquiera existe Torremocha.
     *
     * @param Helper $helper
     * @param NodeRepository $nodeRepository
     * @return Node|null
     */
    private function resolveBasketNode(Helper $helper, NodeRepository $nodeRepository): ?Node
    {
        return $helper->getBasketNode()
            ?? $nodeRepository->findOneBy(['name' => HelperDeliveryResolver::DEFAULT_NODE_NAME]);
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new', name: 'helper_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $helper = new Helper();
        $form = $this->createForm(HelperType::class, $helper);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($helper);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Voluntario «%s» creado.', $helper->getName()));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        return $this->render('helper/new.html.twig', [
            'helper' => $helper,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param Request $request
     * @param Helper $helper
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'helper_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Helper $helper, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(HelperType::class, $helper);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', sprintf('Voluntario «%s» actualizado.', $helper->getName()));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        return $this->render('helper/edit.html.twig', [
            'helper' => $helper,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Borra un voluntario. No se permite si tiene estancias registradas: primero
     * hay que retirarlas (un borrado en cascada se llevaría su historial).
     *
     * @param Request $request
     * @param Helper $helper
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'helper_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Helper $helper, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $helper->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        if ($helper->getStays()->count() > 0) {
            $this->addFlash('error', sprintf(
                'No se puede borrar «%s»: tiene %d estancia(s) registrada(s). Retíralas antes.',
                $helper->getName(),
                $helper->getStays()->count(),
            ));

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        $entityManager->remove($helper);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Voluntario «%s» borrado.', $helper->getName()));

        return $this->redirectToRoute('helper_index');
    }
}
