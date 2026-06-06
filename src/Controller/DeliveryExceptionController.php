<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Form\DeliveryExceptionType;
use App\Repository\BasketRepository;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\NodeRepository;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de las excepciones de calendario de reparto (festivos, cierres,
 * traslados de día). Permite planificarlas por adelantado apuntando a un
 * ciclo futuro y, opcionalmente, a un nodo concreto.
 *
 * Vive bajo /gestion/reparto/excepciones; no choca con delivery_show
 * (/gestion/reparto/{basketId} exige \d+).
 *
 * Sub-fase 8.8d (2026-05-27).
 */
#[Route('/gestion/reparto/excepciones')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryExceptionController extends AbstractController
{
    /**
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/', name: 'delivery_exception_index', methods: ['GET'])]
    public function index(DeliveryExceptionRepository $repository): Response
    {
        $all = $repository->createQueryBuilder('e')
            ->join('e.basket', 'b')
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Separa por el viernes-ciclo: las próximas son las planificables;
        // las pasadas, histórico (más reciente primero).
        $today = new \DateTimeImmutable('today');
        $upcoming = [];
        $past = [];
        foreach ($all as $exception) {
            if ($exception->getBasket()->getDate() >= $today) {
                $upcoming[] = $exception;
            } else {
                $past[] = $exception;
            }
        }

        return $this->render('delivery_exception/index.html.twig', [
            'upcoming' => $upcoming,
            'past' => array_reverse($past),
            'total' => count($all),
        ]);
    }

    /**
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/new', name: 'delivery_exception_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, DeliveryExceptionRepository $repository, NodeRepository $nodeRepository, BasketRepository $basketRepository, NodeDeliveryDate $deliveryDate): Response
    {
        $exception = new DeliveryException();
        $form = $this->createForm(DeliveryExceptionType::class, $exception);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isDuplicate($repository, $exception)) {
                $this->addFlash('error', 'Ya existe una excepción para ese ciclo y nodo. Edítala en vez de crear otra.');
            } else {
                $entityManager->persist($exception);
                $entityManager->flush();
                $this->addFlash('success', 'Excepción de reparto creada.');

                return $this->redirectToRoute('delivery_exception_index');
            }
        }

        return $this->render('delivery_exception/new.html.twig', [
            'exception' => $exception,
            'form' => $form->createView(),
            'scopes' => $this->buildScopes($nodeRepository, $basketRepository, $deliveryDate, $repository, null),
        ]);
    }

    /**
     * @param Request $request
     * @param DeliveryException $exception
     * @param EntityManagerInterface $entityManager
     * @param DeliveryExceptionRepository $repository
     * @return Response
     */
    #[Route('/{id}/edit', name: 'delivery_exception_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DeliveryException $exception, EntityManagerInterface $entityManager, DeliveryExceptionRepository $repository, NodeRepository $nodeRepository, BasketRepository $basketRepository, NodeDeliveryDate $deliveryDate): Response
    {
        $form = $this->createForm(DeliveryExceptionType::class, $exception);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isDuplicate($repository, $exception)) {
                $this->addFlash('error', 'Ya existe otra excepción para ese ciclo y nodo.');
            } else {
                $entityManager->flush();
                $this->addFlash('success', 'Excepción de reparto actualizada.');

                return $this->redirectToRoute('delivery_exception_index');
            }
        }

        return $this->render('delivery_exception/edit.html.twig', [
            'exception' => $exception,
            'form' => $form->createView(),
            'scopes' => $this->buildScopes($nodeRepository, $basketRepository, $deliveryDate, $repository, $exception),
        ]);
    }

    /**
     * @param Request $request
     * @param DeliveryException $exception
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'delivery_exception_delete', methods: ['POST'])]
    public function delete(Request $request, DeliveryException $exception, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $exception->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('delivery_exception_index');
        }

        $entityManager->remove($exception);
        $entityManager->flush();
        $this->addFlash('success', 'Excepción de reparto borrada.');

        return $this->redirectToRoute('delivery_exception_index');
    }

    /**
     * ¿Existe ya otra excepción para el mismo (ciclo, nodo)? Suple el
     * UNIQUE(basket, node) que MySQL no garantiza con node_id NULL.
     *
     * @param DeliveryExceptionRepository $repository
     * @param DeliveryException $exception Excepción que se intenta guardar.
     * @return bool True si chocaría con una fila distinta ya existente.
     */
    private function isDuplicate(DeliveryExceptionRepository $repository, DeliveryException $exception): bool
    {
        $existing = $repository->findOneExact($exception->getBasket(), $exception->getNode());

        return $existing !== null && $existing->getId() !== $exception->getId();
    }

    /** Ventana de semanas futuras a inspeccionar para el picker. Holgada para
     *  que un nodo quincenal tenga suficientes repartos operativos dentro. */
    private const PICKER_WEEKS = 12;

    /** Cuántas fechas ofrecer por alcance (las más próximas; el resto se deja
     *  fuera por YAGNI — no hay aún selector de fecha lejana). */
    private const PICKER_DATES = 4;

    /** Días de la semana en español indexados por número ISO (1 = lunes). */
    private const WEEKDAYS_ES = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
        5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    /**
     * Construye los "alcances" que pinta el formulario como tarjetas: primero
     * "Todos los nodos" (cierre) con las próximas semanas-ciclo, y luego un
     * bloque por nodo con sus fechas físicas reales de reparto (el miércoles
     * de Madrid, el viernes de Torremocha), de modo que el usuario reconozca
     * el día y no un "viernes" que no le corresponde.
     *
     * @param NodeRepository $nodeRepository
     * @param BasketRepository $basketRepository
     * @param NodeDeliveryDate $deliveryDate
     * @param DeliveryExceptionRepository $exceptionRepository
     * @param DeliveryException|null $current Excepción que se edita (su propia
     *        fecha sí se sigue ofreciendo); null al crear una nueva.
     * @return array<int, array{key: string, nodeId: int|null, label: string, sublabel: string, dates: array<int, array{basketId: int, date: \DateTimeImmutable}>}>
     */
    private function buildScopes(NodeRepository $nodeRepository, BasketRepository $basketRepository, NodeDeliveryDate $deliveryDate, DeliveryExceptionRepository $exceptionRepository, ?DeliveryException $current): array
    {
        $baskets = $this->futureBaskets($basketRepository);
        $occupied = $this->occupiedKeys($exceptionRepository, $current);

        // Cierre general: las próximas semanas SIN excepción global, ancladas a
        // su viernes-ciclo. Se sigue rellenando hasta PICKER_DATES saltando las
        // ya ocupadas, no recortando a las 4 primeras.
        $allDates = [];
        foreach ($baskets as $basket) {
            if (isset($occupied[self::scopeKey($basket->getId(), null)])) {
                continue; // esa semana ya tiene un cierre general.
            }
            $allDates[] = [
                'basketId' => $basket->getId(),
                'date' => \DateTimeImmutable::createFromInterface($basket->getDate()),
            ];
            if (count($allDates) >= self::PICKER_DATES) {
                break;
            }
        }

        $scopes = [[
            'key' => 'all',
            'nodeId' => null,
            'label' => 'Todos los nodos',
            'sublabel' => 'Cierre: no hay reparto',
            'dates' => $allDates,
        ]];

        foreach ($nodeRepository->findBy([], ['name' => 'ASC']) as $node) {
            $dates = [];
            foreach ($baskets as $basket) {
                $physical = $deliveryDate->operativeDateFor($basket, $node);
                if ($physical === null) {
                    continue; // nodo quincenal: esta semana no reparte.
                }
                if (isset($occupied[self::scopeKey($basket->getId(), $node->getId())])) {
                    continue; // ese nodo ya tiene excepción ese ciclo.
                }
                $dates[] = ['basketId' => $basket->getId(), 'date' => $physical];
                if (count($dates) >= self::PICKER_DATES) {
                    break;
                }
            }

            $scopes[] = [
                'key' => 'node-' . $node->getId(),
                'nodeId' => $node->getId(),
                'label' => $node->getName(),
                'sublabel' => $this->nodeSublabel($node),
                'dates' => $dates,
            ];
        }

        return $scopes;
    }

    /**
     * Clave que identifica un alcance (ciclo, nodo) para cruzar las fechas del
     * picker con las excepciones ya existentes. nodeId null = cierre global.
     *
     * @param int $basketId Id del ciclo.
     * @param int|null $nodeId Id del nodo, o null para el alcance global.
     * @return string Clave estable "basketId|nodeId".
     */
    public static function scopeKey(int $basketId, ?int $nodeId): string
    {
        return $basketId . '|' . ($nodeId ?? '');
    }

    /**
     * Conjunto de claves (ciclo, nodo) que YA tienen excepción registrada, para
     * que el picker no vuelva a ofrecerlas. Capa fina sobre la query; la lógica
     * de claves vive en {@see occupiedKeysFrom()} (pura, testeable).
     *
     * @param DeliveryExceptionRepository $exceptionRepository
     * @param DeliveryException|null $current Excepción editada, o null al crear.
     * @return array<string, true> Mapa clave => true (lookup O(1) con isset).
     */
    private function occupiedKeys(DeliveryExceptionRepository $exceptionRepository, ?DeliveryException $current): array
    {
        return self::occupiedKeysFrom(
            $exceptionRepository->findFromDate(new \DateTimeImmutable('today')),
            $current,
        );
    }

    /**
     * Deriva el set de claves ocupadas a partir de las excepciones existentes.
     * En edición se excluye la propia excepción que se está editando (por id):
     * su fecha debe seguir disponible para que la tarjeta seleccionada se pinte.
     * Lógica pura (sin BBDD) para poder testearla aislada.
     *
     * @param DeliveryException[] $existing Excepciones ya registradas.
     * @param DeliveryException|null $current Excepción editada, o null al crear.
     * @return array<string, true> Mapa clave => true (lookup O(1) con isset).
     */
    public static function occupiedKeysFrom(array $existing, ?DeliveryException $current): array
    {
        $occupied = [];
        foreach ($existing as $exception) {
            if ($current !== null && $exception->getId() === $current->getId()) {
                continue;
            }
            $occupied[self::scopeKey($exception->getBasket()->getId(), $exception->getNode()?->getId())] = true;
        }

        return $occupied;
    }

    /**
     * Próximos Basket (semanas) a partir de hoy, tope PICKER_WEEKS.
     *
     * @param BasketRepository $basketRepository
     * @return Basket[]
     */
    private function futureBaskets(BasketRepository $basketRepository): array
    {
        return $basketRepository->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(self::PICKER_WEEKS)
            ->getQuery()
            ->getResult();
    }

    /**
     * Descripción corta de un nodo para la tarjeta de alcance, p.ej.
     * "reparto los miércoles" o "reparto los viernes (quincenal)".
     *
     * @param Node $node
     * @return string
     */
    private function nodeSublabel(Node $node): string
    {
        $weekday = self::WEEKDAYS_ES[$node->getDeliveryWeekday()] ?? '—';
        $cadence = $node->getCadence() === Node::CADENCE_BIWEEKLY ? ' (quincenal)' : '';

        return sprintf('reparto los %s%s', $weekday, $cadence);
    }
}
