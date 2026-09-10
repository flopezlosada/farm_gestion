<?php

namespace App\Controller;

use App\Entity\CronRun;
use App\Entity\NotificationLog;
use App\Repository\CronRunRepository;
use App\Repository\EmittedEffectRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\PartnerRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Cron\CronTaskRegistry;
use App\Service\Delivery\PickupNoticeCoverage;
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
 * Tres pestañas porque son tres preguntas distintas, y en un diagnóstico se
 * pasa de una a otra: "¿le llegó a esta persona?" (Envíos), "¿corrió la tarea?"
 * (Ejecuciones) y "¿se quedó alguien sin aviso?" (Cobertura). Se navega entre
 * ellas: desde una ejecución se ven los avisos que salieron de ella.
 *
 * Las dos primeras cuentan lo que el sistema hizo. La tercera compara lo hecho
 * con lo que había que hacer, y es la única capaz de descubrir a quién NO se
 * está avisando: eso, desde dentro, se ve como una tarea en verde.
 */
#[Route('/gestion/avisos')]
#[IsGranted('ROLE_ADMIN')]
class NotificationLogController extends AbstractController
{
    /** Filas por página. */
    private const PER_PAGE = 50;

    /** Tope del rango consultable, en días, para que nadie pida cinco años por error. */
    private const MAX_DAYS = 400;

    /**
     * Cuántos repartos seguidos se miran en la pestaña de cobertura. Cada uno
     * cuesta sus consultas, así que no es una lista larga: con ocho ya se ve si
     * un hueco es de un día o se repite cada dos semanas, que es lo que hay que
     * distinguir.
     */
    private const COVERAGE_DATES = 8;

    public function __construct(
        private readonly NotificationLogRepository $logs,
        private readonly CronRunRepository $runs,
        private readonly CronTaskRegistry $cronTasks,
        private readonly PartnerRepository $partners,
    ) {
    }

    /**
     * Los envíos: quién recibió qué, por qué canal y con qué resultado.
     */
    #[Route('', name: 'notification_log_index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $filters = $this->resolveFilters($request, [
            NotificationLog::STATUS_SENT,
            NotificationLog::STATUS_FAILED,
            NotificationLog::STATUS_DISCARDED,
        ]);

        $pagination = $paginator->paginate(
            $this->logs->findFilteredQb($filters)->getQuery(),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('notification_log/index.html.twig', [
            'pagination' => $pagination,
            'partners_by_email' => $this->partnersByEmail($pagination),
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
        $filters = $this->resolveFilters($request, [
            CronRun::STATUS_DONE,
            CronRun::STATUS_NOTHING_TO_DO,
            CronRun::STATUS_DISABLED,
            CronRun::STATUS_FAILED,
        ]);

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
     * Cobertura: de quienes recogían cada día, a cuántxs se avisó.
     *
     * Es la pestaña que contesta la pregunta que nadie estaba haciendo. Las
     * otras dos cuentan lo que el sistema hizo; ésta compara lo que hizo con lo
     * que había que hacer, y es la única que puede descubrir a quién NO se
     * avisa — un fallo que desde dentro se ve como una tarea en verde.
     *
     * Se calculan varias fechas seguidas a propósito, aunque cada una cueste
     * sus consultas: un hueco suelto no dice nada, y el mismo hueco cada dos
     * semanas es un fallo del sistema. El patrón sólo se ve en la serie.
     */
    #[Route('/cobertura', name: 'notification_log_coverage', methods: ['GET'])]
    public function coverage(
        Request $request,
        PickupNoticeCoverage $coverage,
        WeeklyBasketRepository $baskets,
        EmittedEffectRepository $effects,
    ): Response {
        $dates = $baskets->recentDeliveryDates(self::COVERAGE_DATES);

        $series = [];
        foreach ($dates as $row) {
            $resultado = $coverage->forDate(new \DateTimeImmutable($row['date']));
            $series[$row['date']] = $resultado['totals'] + ['unrecorded' => $resultado['unrecorded']];
        }

        // Por defecto se abre el reparto más reciente, que es el que se acaba
        // de hacer y por el que preguntará la gente. Pero se admite CUALQUIER
        // fecha, no sólo las de la serie: el fallo de las compartidas se
        // descubrió en septiembre y venía de julio, así que poder retroceder a
        // un reparto viejo es justo lo que hace falta para acotar desde cuándo.
        $selected = $request->query->getString('date') ?: (string) (array_key_first($series) ?? '');
        $day = $selected !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $selected) : false;
        $detail = $day instanceof \DateTimeImmutable ? $coverage->forDate($day) : null;

        return $this->render('notification_log/coverage.html.twig', [
            'series' => $series,
            'selected' => $selected,
            'detail' => $detail,
            // Desde cuándo hay constancia. Se enseña para que un reparto viejo
            // sin datos no se lea como un reparto sin avisar.
            'first_record' => $effects->earliestOccurredOn(PickupNoticeCoverage::noticeKinds()),
            // El rango no pinta nada aquí (la serie va por repartos, no por
            // días), pero el layout lo necesita para las pestañas y para que
            // volver a Envíos conserve el periodo que traías.
            'filters' => $this->resolveFilters($request, []),
            'presets' => $this->presets(),
        ]);
    }

    /**
     * A quién corresponde cada dirección de correo de la página, en UNA
     * consulta.
     *
     * El registro del correo guarda la dirección y no la persona, porque
     * resolverla en el momento del envío costaría una consulta por correo justo
     * en la tarea que más gente toca. Pero una tabla con cincuenta direcciones
     * sueltas no se lee, así que se cruzan aquí: se listan los correos de la
     * página y se piden sus fichas de golpe.
     *
     * @param iterable<\App\Entity\NotificationLog> $logs Los de la página actual.
     * @return array<string, \App\Entity\Partner> correo => socix
     */
    private function partnersByEmail(iterable $logs): array
    {
        $emails = [];
        foreach ($logs as $log) {
            if ($log->getPartner() === null && $log->getChannel() === NotificationLog::CHANNEL_EMAIL) {
                $emails[$log->getTarget()] = true;
            }
        }

        if ($emails === []) {
            return [];
        }

        $byEmail = [];
        foreach ($this->partners->findBy(['email' => array_keys($emails)]) as $partner) {
            $byEmail[(string) $partner->getEmail()] = $partner;
        }

        return $byEmail;
    }

    /**
     * Filtros de la query, por whitelist y con defaults seguros. Los comparten
     * las dos pestañas para que cambiar de pestaña conserve el rango que
     * estabas mirando; cada una usa los que entiende y descarta el resto.
     *
     * LOS ESTADOS VÁLIDOS LOS PONE CADA PESTAÑA, porque el vocabulario no es el
     * mismo: un envío está entregado, fallido o descartado; una ejecución hizo
     * trabajo, no tenía nada que hacer, estaba apagada o falló. Admitiendo los
     * de las dos, una URL guardada con `?status=nothing_to_do` daría cero
     * avisos sin explicar por qué — que es exactamente la confusión que esta
     * pantalla existe para quitar.
     *
     * Nota: {@see UsageStatsController} resuelve su rango de forma equivalente.
     * Son dos usos y cada uno tiene sus propios campos, así que de momento no
     * se extrae; si aparece un tercero, toca sacarlo a una pieza común.
     *
     * @param string[] $allowedStatuses Estados que entiende la pestaña que llama.
     * @return array{
     *     from: \DateTimeImmutable, to: \DateTimeImmutable, until: \DateTimeImmutable,
     *     channel: ?string, kind: ?string, status: ?string, task: ?string, q: ?string, run: ?int
     * }
     */
    private function resolveFilters(Request $request, array $allowedStatuses): array
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
            'status' => $this->whitelisted($request, 'status', $allowedStatuses),
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
