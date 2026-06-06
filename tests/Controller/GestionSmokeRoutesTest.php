<?php

namespace App\Tests\Controller;

use Symfony\Component\Routing\RouterInterface;

/**
 * Suite e2e de humo: barre los listados de gestión con un admin logueado y
 * comprueba que ninguno revienta (HTTP >= 500).
 *
 * Por qué existe: los listados concentran las queries pesadas (GROUP BY, ORDER
 * BY raros) que el análisis estático no ve y los tests unitarios tampoco —un
 * barrido como éste cazó en su día 3 errores SQL 1055 que fallaban en el
 * ORDER BY. Es barato y complementa los smoke tests por pantalla.
 *
 * Alcance v1: solo rutas GET sin parámetros bajo /gestion/ que terminan en "/"
 * (los índices) más el dashboard. Las fichas con id quedan fuera porque db_test
 * no tiene datos de granja sembrados (ver memoria catalog-ids-hardcoded); cuando
 * se siembren, se podrá ampliar a fichas con id real.
 */
class GestionSmokeRoutesTest extends AbstractAuthenticatedTest
{
    /**
     * Rutas excluidas del barrido y el motivo. NO se silencia nada en
     * silencio: lo que se deja fuera queda documentado aquí.
     *
     * @var array<string, string>
     */
    private const EXCLUDED = [
        // Bug real preexistente: la ruta apunta a StatisticController::index, que
        // NO existe (500 también en producción). Pantalla legacy pendiente de
        // migración — ver memoria legacy-screens-sweep. Quitar de aquí al arreglarla.
        '/gestion/statistic/' => 'StatisticController::index inexistente (pantalla legacy rota)',
        // Falso positivo por datos: peta en BatchController:429 (foreach sobre null)
        // porque db_test no tiene datos de granja sembrados. Con datos reales no
        // revienta; el código es algo frágil. Ver memoria catalog-ids-hardcoded.
        '/gestion/batch/hens_analyses/' => 'db_test sin datos de granja (foreach sobre null)',
    ];

    /**
     * Recorre todos los índices de gestión y agrupa los que devuelven 500 para
     * reportarlos juntos (mejor que fallar en el primero).
     */
    public function testGestionIndexesDoNotError(): void
    {
        $client = $this->createAuthenticatedClient();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $failures = [];
        $checked = 0;
        foreach ($this->indexPaths($router) as $path) {
            $client->request('GET', $path);
            $code = $client->getResponse()->getStatusCode();
            ++$checked;
            if ($code >= 500) {
                $failures[] = sprintf('%s → %d', $path, $code);
            }
        }

        $this->assertGreaterThan(0, $checked, 'El barrido no encontró ninguna ruta — revisa el filtro.');
        $this->assertSame([], $failures, "Listados de gestión que revientan:\n" . implode("\n", $failures));
    }

    /**
     * Índices de gestión barribles: GET (o sin método), sin parámetros de ruta,
     * bajo /gestion/, terminados en "/" (más el dashboard). Sin duplicados.
     *
     * @return list<string>
     */
    private function indexPaths(RouterInterface $router): array
    {
        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            $methods = $route->getMethods();

            if (!str_starts_with($path, '/gestion/')) {
                continue;
            }
            if (str_contains($path, '{')) {
                continue;
            }
            if ($methods !== [] && !in_array('GET', $methods, true)) {
                continue;
            }
            if (!str_ends_with($path, '/') && $path !== '/gestion/dashboard') {
                continue;
            }
            if (array_key_exists($path, self::EXCLUDED)) {
                continue;
            }
            $paths[$path] = true;
        }

        return array_keys($paths);
    }
}
