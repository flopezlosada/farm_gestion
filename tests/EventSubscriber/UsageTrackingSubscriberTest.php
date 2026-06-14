<?php

namespace App\Tests\EventSubscriber;

use App\Entity\UsageHit;
use App\EventSubscriber\UsageTrackingSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests del registro de telemetría anónima de uso. El subscriber escucha
 * kernel.terminate y, para las visitas de la zona privada (/panel y /gestion),
 * inserta una fila en usage_hit por DBAL. Lo que se verifica aquí: que mide
 * sólo la zona privada, que el rastro es anónimo y correlacionable por sesión,
 * y que un fallo al insertar nunca se propaga (la telemetría es best-effort).
 */
class UsageTrackingSubscriberTest extends TestCase
{
    private const SECRET = 'test-secret';

    /**
     * Fabrica el evento terminate que el subscriber recibiría para una petición
     * a $path con el método y la respuesta dados. Opcionalmente fija el nombre
     * de ruta y una sesión ya iniciada.
     */
    private function terminateEvent(
        string $path,
        string $method = 'GET',
        int $status = 200,
        ?string $routeName = null,
        ?Session $session = null,
    ): TerminateEvent {
        $request = Request::create($path, $method);
        if ($routeName !== null) {
            $request->attributes->set('_route', $routeName);
        }
        if ($session !== null) {
            $request->setSession($session);
        }

        return new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            new Response('', $status),
        );
    }

    private function subscriber(Connection $connection, ?LoggerInterface $logger = null): UsageTrackingSubscriber
    {
        return new UsageTrackingSubscriber(
            $connection,
            $logger ?? $this->createMock(LoggerInterface::class),
            self::SECRET,
        );
    }

    /**
     * Una visita a /gestion inserta una fila con área "gestion" y los datos de
     * la petición (ruta, método, código). Sin sesión, session_hash es null.
     */
    public function testInsertsHitForGestionPath(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $this->assertSame('usage_hit', $table);
                $captured = $data;

                return 1;
            });

        $this->subscriber($connection)->onKernelTerminate(
            $this->terminateEvent('/gestion/partners', 'POST', 302, 'partner_index'),
        );

        $this->assertSame(UsageHit::AREA_GESTION, $captured['area']);
        $this->assertSame('partner_index', $captured['route_name']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame(302, $captured['status_code']);
        $this->assertNull($captured['session_hash']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured['occurred_at']);
    }

    /**
     * Una visita a /panel se clasifica como área "panel".
     */
    public function testInsertsHitForPanelPath(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;

                return 1;
            });

        $this->subscriber($connection)->onKernelTerminate($this->terminateEvent('/panel'));

        $this->assertSame(UsageHit::AREA_PANEL, $captured['area']);
    }

    /**
     * Una ruta sin nombre (p. ej. un 404) se registra igual, con route_name
     * null: interesa saber que hubo tráfico aunque no se resolviera a ninguna
     * ruta.
     */
    public function testInsertsHitWithNullRouteName(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;

                return 1;
            });

        $this->subscriber($connection)->onKernelTerminate(
            $this->terminateEvent('/gestion/inexistente', 'GET', 404),
        );

        $this->assertNull($captured['route_name']);
        $this->assertSame(404, $captured['status_code']);
    }

    /**
     * Fuera de la zona privada (web pública, /login, raíz…) no se mide nada.
     *
     * @dataProvider publicPaths
     */
    public function testIgnoresPathsOutsidePrivateZone(string $path): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('insert');

        $this->subscriber($connection)->onKernelTerminate($this->terminateEvent($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicPaths(): array
    {
        return [
            'raíz' => ['/'],
            'login' => ['/login'],
            'blog público' => ['/blog/una-entrada'],
            // Un prefijo que sólo empieza igual NO debe colar como zona privada.
            'falso prefijo' => ['/gestionado-por-terceros'],
        ];
    }

    /**
     * El session_hash es el sha256 no reversible del id de sesión combinado con
     * el secreto: anónimo pero estable para correlacionar la misma visita.
     */
    public function testSessionHashIsDeterministicSha256OfSessionIdAndSecret(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;

                return 1;
            });

        $this->subscriber($connection)->onKernelTerminate(
            $this->terminateEvent('/gestion', session: $session),
        );

        $expected = hash('sha256', $session->getId() . self::SECRET);
        $this->assertSame($expected, $captured['session_hash']);
        // No se filtra el id de sesión en claro.
        $this->assertStringNotContainsString($session->getId(), (string) $captured['session_hash']);
    }

    /**
     * La telemetría es best-effort: si el INSERT revienta (BBDD caída, tabla
     * inexistente…), el subscriber se traga el error, lo deja en el log como
     * warning y NO lo propaga — registrar una visita jamás debe tumbar la web.
     */
    public function testSwallowsInsertFailureAndLogsWarning(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('BBDD caída'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->subscriber($connection, $logger)->onKernelTerminate(
            $this->terminateEvent('/gestion'),
        );
    }
}
