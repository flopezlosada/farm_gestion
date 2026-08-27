<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\BasketRepository;
use App\Repository\NodeRepository;
use App\Service\Delivery\EggRescheduleNotifier;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\NodeEggRescheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Retirar o trasladar los HUEVOS de un reparto entero, para uno o varios puntos
 * de recogida. Petición de administración: cuando una semana no hay huevos
 * (muda, incidencia en la granja) hay que actuar sobre el punto de recogida
 * completo, no socio a socio.
 *
 * Flujo en una sola pantalla, refinando por GET: se elige la semana, luego los
 * puntos de recogida, y con eso la pantalla YA enseña quién se ve afectado y
 * cuántas docenas. El traslado (o la retirada) se confirma con un POST sobre
 * esa misma lista. Es la "página intermedia" que pide cualquier operación
 * masiva: nadie pulsa un botón sin ver antes los nombres.
 *
 * La mecánica vive entera en {@see NodeEggRescheduler}; aquí sólo hay
 * resolución de parámetros, CSRF, transacción y mensajes.
 */
#[Route('/gestion/reparto/huevos')]
#[IsGranted('ROLE_GESTION_REPARTO')]
class NodeEggRescheduleController extends AbstractController
{
    /** Semanas hacia delante que se ofrecen como reparto de origen. */
    private const ORIGIN_WEEKS = 6;

    /**
     * Pantalla de la operación: selección y previsualización.
     *
     * @param Request            $request       `basket` (semana origen), `nodes[]`, `to` (destino).
     * @param NodeRepository     $nodeRepo
     * @param BasketRepository   $basketRepo
     * @param NodeEggRescheduler $rescheduler
     * @param NodeDeliveryDate   $nodeDeliveryDate Fecha física de cada nodo, para etiquetar.
     * @return Response
     */
    #[Route('', name: 'egg_reschedule_index', methods: ['GET'])]
    public function index(
        Request $request,
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
        NodeEggRescheduler $rescheduler,
        NodeDeliveryDate $nodeDeliveryDate,
    ): Response {
        $weeks = $this->originWeeks($basketRepo);
        $basket = $this->resolveBasket($basketRepo, $request->query->get('basket'), $weeks);
        $allNodes = $nodeRepo->findBy([], ['name' => 'ASC']);
        $nodes = $this->resolveNodes($allNodes, $request->query->all('nodes'));

        // Fecha física de cada nodo en la semana elegida: null = ese nodo no
        // reparte esa semana (cadencia quincenal, cierre), así que no se ofrece.
        $nodeDates = [];
        foreach ($allNodes as $node) {
            $nodeDates[$node->getId()] = $basket !== null
                ? $nodeDeliveryDate->operativeDateFor($basket, $node)
                : null;
        }
        $nodes = array_values(array_filter($nodes, static fn (Node $n): bool => $nodeDates[$n->getId()] !== null));

        $affected = $basket !== null && $nodes !== [] ? $rescheduler->affected($basket, $nodes) : [];
        $destinations = $basket !== null && $nodes !== [] ? $rescheduler->destinations($basket, $nodes) : [];

        return $this->render('delivery_eggs/index.html.twig', [
            'weeks' => $weeks,
            'basket' => $basket,
            'all_nodes' => $allNodes,
            'node_dates' => $nodeDates,
            'selected_node_ids' => array_map(static fn (Node $n): int => $n->getId(), $nodes),
            'affected' => $affected,
            'total_dozens' => array_sum(array_column($affected, 'dozens')),
            'destinations' => $destinations,
        ]);
    }

    /**
     * Ejecuta la retirada o el traslado sobre los socios previsualizados.
     *
     * @param Request                $request     `basket`, `nodes[]`, `to` ('' = no recolocar), `_token`.
     * @param NodeRepository         $nodeRepo
     * @param BasketRepository       $basketRepo
     * @param NodeEggRescheduler     $rescheduler
     * @param EntityManagerInterface $em          Para envolver el lote en una transacción.
     * @param EggRescheduleNotifier  $notifier    Avisa por email a los afectados.
     * @return Response Redirección a la pantalla con el resultado en flashes.
     */
    #[Route('/aplicar', name: 'egg_reschedule_apply', methods: ['POST'])]
    public function apply(
        Request $request,
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
        NodeEggRescheduler $rescheduler,
        EntityManagerInterface $em,
        EggRescheduleNotifier $notifier,
    ): Response {
        $basketId = (int) $request->request->get('basket');
        $nodeIds = array_map('intval', (array) $request->request->all('nodes'));
        $toId = (int) $request->request->get('to');

        if (!$this->isCsrfTokenValid('egg_reschedule', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La sesión ha caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('egg_reschedule_index');
        }

        $basket = $basketRepo->find($basketId);
        $nodes = $nodeIds !== [] ? $nodeRepo->findBy(['id' => $nodeIds]) : [];
        $to = $toId > 0 ? $basketRepo->find($toId) : null;

        if (!$basket instanceof Basket) {
            $this->addFlash('error', 'No se ha encontrado el reparto seleccionado.');

            return $this->redirectToRoute('egg_reschedule_index');
        }

        try {
            // Transacción: el lote toca a muchos socios y cada paso hace su
            // propio flush; un fallo a mitad dejaría medio punto de recogida
            // trasladado y medio no.
            $result = $em->wrapInTransaction(
                fn (): array => $rescheduler->apply($basket, $nodes, $to, 'gestor:' . $this->getUser()?->getId()),
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('egg_reschedule_index', $this->backParams($basket, $nodes));
        }

        // El aviso va FUERA de la transacción: un SMTP caído no debe deshacer un
        // cambio de reparto ya aplicado. Al revés sí importaría (avisar de algo
        // que luego se revierte), y por eso se envía después del commit.
        $sent = $notifier->notify($result['notify'], $basket, $to);

        $this->reportResult($result, $to, $sent);

        return $this->redirectToRoute('egg_reschedule_index', $this->backParams($basket, $nodes));
    }

    /**
     * Resume en flashes lo que ha hecho el lote: cuántos socios, cuántas
     * docenas, cuántos avisos han salido, y qué casos se han dejado intactos y
     * por qué.
     *
     * @param array{moved: int, removed: int, helpers: int, dozens: float, skipped: list<string>, notify: list<array<string,mixed>>} $result
     * @param Basket|null $to   Semana destino, o null si se retiraron sin recolocar.
     * @param int         $sent Avisos por email efectivamente enviados.
     */
    private function reportResult(array $result, ?Basket $to, int $sent): void
    {
        $touched = $result['moved'] + $result['removed'] + $result['helpers'];
        if ($touched === 0 && $result['skipped'] === []) {
            $this->addFlash('warning', 'Nadie llevaba huevos en ese reparto: no se ha cambiado nada.');

            return;
        }

        $dozens = rtrim(rtrim(number_format($result['dozens'], 2, ',', '.'), '0'), ',');
        if ($to !== null) {
            $this->addFlash('success', sprintf(
                'Trasladadas %s docenas al reparto del %s: %d socio(s) las recogerán ese día, con los huevos de las dos semanas.',
                $dozens,
                $to->getDate()?->format('d/m/Y') ?? '?',
                $result['moved'],
            ));
        } elseif ($result['removed'] > 0) {
            $this->addFlash('success', sprintf(
                'Retiradas %s docenas de %d socio(s). No se han recolocado: esas docenas no se entregarán y la cuota del mes no varía.',
                $dozens,
                $result['removed'],
            ));
        }

        // Los voluntarios del albergue van SIEMPRE por retirada, también cuando
        // la operación es un traslado: su cesta se deriva de la estancia y no
        // admite acumular. Se dice aparte para no venderlo como un traslado.
        if ($result['helpers'] > 0) {
            $this->addFlash('warning', sprintf(
                '%d voluntario(s) del albergue se quedan sin huevos esa semana%s: su cesta no admite trasladarlos a otro día.',
                $result['helpers'],
                $to !== null ? ' y no los recuperan' : '',
            ));
        }

        if ($sent > 0) {
            $this->addFlash('success', sprintf('Se ha avisado por email a %d persona(s).', $sent));
        } elseif ($result['notify'] !== []) {
            $this->addFlash('warning', 'No ha salido ningún aviso por email: comprueba el interruptor general de correo y que haya emails en las fichas.');
        }

        if ($result['skipped'] !== []) {
            $this->addFlash('warning', sprintf(
                'Sin tocar (%d): %s',
                count($result['skipped']),
                implode(' · ', $result['skipped']),
            ));
        }
    }

    /**
     * Parámetros para volver a la pantalla con la misma selección.
     *
     * @param Basket $basket Semana de origen.
     * @param Node[] $nodes  Puntos de recogida seleccionados.
     * @return array<string, mixed>
     */
    private function backParams(Basket $basket, array $nodes): array
    {
        return [
            'basket' => $basket->getId(),
            'nodes' => array_map(static fn (Node $n): int => $n->getId(), $nodes),
        ];
    }

    /**
     * Semanas ofrecidas como reparto de origen: desde hoy (incluido) hacia
     * delante. Hoy entra a propósito — la falta de huevos se descubre la misma
     * mañana del reparto; ver el docblock de {@see NodeEggRescheduler}.
     *
     * @param BasketRepository $basketRepo
     * @return Basket[]
     */
    private function originWeeks(BasketRepository $basketRepo): array
    {
        return $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(self::ORIGIN_WEEKS)
            ->getQuery()
            ->getResult();
    }

    /**
     * Semana de origen elegida, o la primera disponible si no se ha elegido
     * ninguna (la pantalla nunca se abre en blanco).
     *
     * @param BasketRepository $basketRepo
     * @param mixed            $requested Id recibido por query string.
     * @param Basket[]         $weeks     Semanas ofrecidas.
     * @return Basket|null Null sólo si no hay ninguna semana futura.
     */
    private function resolveBasket(BasketRepository $basketRepo, mixed $requested, array $weeks): ?Basket
    {
        if (is_numeric($requested)) {
            $basket = $basketRepo->find((int) $requested);
            if ($basket instanceof Basket) {
                return $basket;
            }
        }

        return $weeks[0] ?? null;
    }

    /**
     * Nodos seleccionados, filtrando ids que no existan.
     *
     * @param Node[] $allNodes Nodos existentes.
     * @param array<mixed> $requested Ids recibidos por query string.
     * @return Node[]
     */
    private function resolveNodes(array $allNodes, array $requested): array
    {
        $wanted = array_map('intval', $requested);
        if ($wanted === []) {
            return [];
        }

        return array_values(array_filter(
            $allNodes,
            static fn (Node $node): bool => in_array($node->getId(), $wanted, true),
        ));
    }
}
