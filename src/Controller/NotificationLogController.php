<?php

namespace App\Controller;

use App\Entity\CronRun;
use App\Entity\NotificationLog;
use App\Repository\CronRunRepository;
use App\Repository\NotificationLogRepository;
use App\Service\Cron\CronTaskRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Registro de lo que el sistema envía y de las tareas que lo envían.
 *
 * Nace de un diagnóstico que costó media docena de consultas a mano contra la
 * base de producción: una socia dijo que no le llegaba el aviso de su cesta y
 * en la web no había forma de comprobar si se le había mandado. /gestion/settings
 * enseñaba la ÚLTIMA ejecución de cada tarea —nada de hace un mes— y los envíos
 * no se veían en ninguna parte.
 *
 * Dos pestañas porque son dos preguntas distintas, y en un diagnóstico se pasa
 * de una a la otra: "¿corrió la tarea?" (Ejecuciones) y "¿le llegó a esta
 * persona?" (Envíos). Se navega entre ellas: desde una ejecución se ven los
 * avisos que salieron de ella.
 */
#[Route('/gestion/avisos')]
#[IsGranted('ROLE_ADMIN')]
class NotificationLogController extends AbstractController
{
    /** Filas por página. */
    private const PER_PAGE = 50;

    /** Tope del rango consultable, en días, para que nadie pida cinco años por error. */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly NotificationLogRepository $logs,
        private readonly CronRunRepository $runs,
        private readonly CronTaskRegistry $cronTasks,
    ) {
    }

    /**
     * Los envíos: quién recibió qué, por qué canal y con qué resultado.
     */
    #[Route('', name: 'notification_log_index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $filters = $this->resolveFilters($request);

        $pagination = $paginator->paginate(
            $this->logs->findFilteredQb($filters)->getQuery(),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('notification_log/index.html.twig', [
            'pagination' => $pagination,
            'summary' => $this->logs->summary($filters),
            'kinds' => $this->logs->distinctKinds(),
            'filters' => $filters,
            'presets' => $this->presets(),
            'channels' => [
                NotificationLog::CHANNEL_EMAIL => 'Correo',
                NotificationLog::CHANNEL_PUSH => 'Móvil',
            ],
            'statuses' => [
                NotificationLog::STATUS_SENT => 'Entregado',
                NotificationLog::STATUS_FAILED => 'Falló',
                NotificationLog::STATUS_DISCARDED => 'Descartado',
            ],
        ]);
    }

    /**
     * Las ejecuciones: el histórico completo de las tareas programadas, no sólo
     * la última de cada una.
     */
    #[Route('/tareas', name: 'notification_log_runs', methods: ['GET'])]
    public function runs(Request $request, PaginatorInterface $paginator): Response
    {
        $filters = $this->resolveFilters($request);

        $pagination = $paginator->paginate(
            $this->runs->findFilteredQb($filters)->getQuery(),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        // Cuántos avisos salió de cada ejecución de la página, en una consulta:
        // es la columna que convierte "corrió" en "corrió e hizo algo".
        $runIds = [];
        foreach ($pagination as $run) {
            $runIds[] = $run->getId();
        }

        return $this->render('notification_log/runs.html.twig', [
            'pagination' => $pagination,
            'sent_by_run' => $this->logs->countByRun($runIds),
            'filters' => $filters,
            'presets' => $this->presets(),
            'tasks' => $this->taskLabels(),
            'statuses' => [
                CronRun::STATUS_DONE => 'Hizo trabajo',
                CronRun::STATUS_NOTHING_TO_DO => 'Nada que hacer',
                CronRun::STATUS_DISABLED => 'Apagada',
                CronRun::STATUS_FAILED => 'Falló',
            ],
        ]);
    }

    /**
     * Filtros de la query, por whitelist y con defaults seguros. Los comparten
     * las dos pestañas para que cambiar de pestaña conserve el rango que
     * estabas mirando; cada una usa los que entiende y descarta el resto.
     *
     * Nota: {@see UsageStatsController} resuelve su rango de forma equivalente.
     * Son dos usos y cada uno tiene sus propios campos, así que de momento no
     * se extrae; si aparece un tercero, toca sacarlo a una pieza común.
     *
     * @return array{
     *     from: \DateTimeImmutable, to: \DateTimeImmutable, until: \DateTimeImmutable,
     *     channel: ?string, kind: ?string, status: ?string, task: ?string, q: ?string, run: ?int
     * }
     */
    private function resolveFilters(Request $request): array
    {
        $today = new \DateTimeImmutable('today');
        $to = $this->parseDate($request->query->getString('to'), $today);
        $from = $this->parseDate($request->query->getString('from'), $today->modify('-29 days'));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $earliest = $to->modify(sprintf('-%d days', self::MAX_DAYS));
        if ($from < $earliest) {
            $from = $earliest;
        }

        return [
            'from' => $from,
            'to' => $to,
            // Límite superior EXCLUSIVO: el registro guarda hora, así que
            // comparar contra "hoy a las 00:00" dejaría fuera todo lo de hoy.
            'until' => $to->modify('+1 day'),
            'channel' => $this->whitelisted($request, 'channel', [NotificationLog::CHANNEL_EMAIL, NotificationLog::CHANNEL_PUSH]),
            'kind' => trim($request->query->getString('kind')) ?: null,
            'status' => $this->whitelisted($request, 'status', [
                NotificationLog::STATUS_SENT,
                NotificationLog::STATUS_FAILED,
                NotificationLog::STATUS_DISCARDED,
                CronRun::STATUS_DONE,
                CronRun::STATUS_NOTHING_TO_DO,
                CronRun::STATUS_DISABLED,
            ]),
            'task' => trim($request->query->getString('task')) ?: null,
            'q' => trim($request->query->getString('q')) ?: null,
            'run' => $request->query->getInt('run') ?: null,
        ];
    }

    /**
     * Devuelve el valor del parámetro sólo si está en la lista permitida. Los
     * filtros viajan a la consulta, así que no se admite lo que llegue.
     *
     * @param string[] $allowed
     */
    private function whitelisted(Request $request, string $param, array $allowed): ?string
    {
        $value = $request->query->getString($param);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Fecha YYYY-MM-DD a medianoche; el fallback si viene vacía o inválida. El
     * '!' fija la hora a 00:00.
     */
    private function parseDate(string $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        if ($value === '') {
            return $fallback;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof \DateTimeImmutable ? $parsed : $fallback;
    }

    /**
     * Atajos de rango con sus fechas ya resueltas. "Esta semana" y "este mes"
     * no están: en un diagnóstico se mira hacia atrás desde hoy, no por
     * calendario natural.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function presets(): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            ['label' => 'Hoy', 'from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => '7 días', 'from' => $today->modify('-6 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => '30 días', 'from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => '3 meses', 'from' => $today->modify('-89 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => 'Un año', 'from' => $today->modify('-364 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        ];
    }

    /**
     * Etiquetas legibles de las tareas del manifiesto, para el desplegable.
     *
     * @return array<string, string> clave => etiqueta
     */
    private function taskLabels(): array
    {
        $labels = [];
        foreach (array_keys($this->cronTasks->all()) as $key) {
            $labels[$key] = $this->cronTasks->label($key);
        }

        return $labels;
    }
}
