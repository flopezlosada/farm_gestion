<?php

namespace App\EventSubscriber;

use App\Entity\UsageHit;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registra una fila en usage_hit por cada visita servida bajo /panel o
 * /gestion: telemetría anónima de uso de la zona privada (ver {@see UsageHit}).
 *
 * Engancha a kernel.terminate, que se dispara DESPUÉS de enviar la respuesta al
 * navegador: la escritura no añade latencia que note el usuario.
 *
 * Inserta por DBAL, NO por el EntityManager, a propósito: un flush() en
 * terminate arrastraría entidades pendientes de la request, y si la petición
 * murió con una excepción el EM queda cerrado. El INSERT directo aísla la
 * telemetría del UnitOfWork. Todo el cuerpo va en try/catch: si registrar el
 * hit falla, se traga el error (lo deja en el log) y la app sigue — la
 * telemetría jamás debe romper la web.
 */
final class UsageTrackingSubscriber
{
    /**
     * Prefijo de path -> área. El orden no importa porque son disjuntos.
     */
    private const AREA_BY_PREFIX = [
        '/panel' => UsageHit::AREA_PANEL,
        '/gestion' => UsageHit::AREA_GESTION,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $area = $this->resolveArea($request->getPathInfo());
        if ($area === null) {
            return; // fuera de la zona privada: no se mide.
        }

        try {
            $this->connection->insert('usage_hit', [
                'area' => $area,
                'route_name' => $request->attributes->get('_route'),
                'method' => $request->getMethod(),
                'status_code' => $event->getResponse()->getStatusCode(),
                'session_hash' => $this->sessionHash($request),
                'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // La telemetría es best-effort: nunca debe afectar al usuario.
            $this->logger->warning('No se pudo registrar el usage_hit: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Área a la que pertenece un path, o null si no es de la zona privada.
     */
    private function resolveArea(string $path): ?string
    {
        foreach (self::AREA_BY_PREFIX as $prefix => $area) {
            if (str_starts_with($path, $prefix)) {
                return $area;
            }
        }
        return null;
    }

    /**
     * Hash no reversible del id de sesión, para correlacionar las peticiones de
     * una misma visita sin identificar a la persona. Null si no hay sesión
     * iniciada (no forzamos crear una solo para medir).
     */
    private function sessionHash(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return null;
        }
        $id = $session->getId();
        return $id === '' ? null : hash('sha256', $id . $this->secret);
    }
}
