<?php

namespace App\Tests\Controller;

use App\Controller\CronTickController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La puerta del planificador, que es el punto más expuesto de todo esto: una URL
 * pública que dispara trabajo real.
 *
 * Se prueba sin kernel a propósito, instanciando el controlador con distintos
 * tokens. Lo que hay que verificar es la decisión de dejar pasar o no, y con un
 * cliente HTTP habría que montar un entorno por caso.
 */
class CronTickControllerTest extends TestCase
{
    private const TOKEN = 'un-token-de-32-bytes-en-hexadecimal';

    /**
     * Sin token configurado, el endpoint NO EXISTE. Es el estado seguro por
     * defecto: un despliegue que olvide la variable deja el planificador
     * apagado, no abierto de par en par.
     */
    public function testSinTokenConfiguradoElEndpointNoExiste(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new CronTickController(''))->tick($this->request(''));
    }

    /**
     * Y tampoco existe si viene un token cualquiera: una cadena vacía enviada
     * contra una configuración vacía no puede colar.
     */
    public function testSinTokenConfiguradoNoColaNingunToken(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new CronTickController(''))->tick($this->request(self::TOKEN));
    }

    /**
     * Con el token equivocado responde 404, no 401 ni 403: quien no lo trae no
     * llega a saber que aquí hay algo.
     */
    public function testConTokenEquivocadoResponde404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new CronTickController(self::TOKEN))->tick($this->request('otro-token'));
    }

    /**
     * Sin la cabecera tampoco pasa nada.
     */
    public function testSinCabeceraResponde404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new CronTickController(self::TOKEN))->tick(Request::create('/cron/tick'));
    }

    /**
     * Con el token bueno acepta el tick y deja la señal para que el trabajo se
     * haga al terminar la petición, ya con la conexión cerrada.
     */
    public function testConElTokenBuenoAceptaYDejaLaSenal(): void
    {
        $request = $this->request(self::TOKEN);

        $response = (new CronTickController(self::TOKEN))->tick($request);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertTrue(
            $request->attributes->get(CronTickController::TICK_ATTRIBUTE),
            'El trabajo lo hace el listener de kernel.terminate a partir de esta señal.'
        );
    }

    /**
     * Petición al tick con un token en la cabecera.
     *
     * @param string $token Token a enviar.
     */
    private function request(string $token): Request
    {
        return Request::create('/cron/tick', 'POST', server: [
            'HTTP_' . str_replace('-', '_', strtoupper(CronTickController::TOKEN_HEADER)) => $token,
        ]);
    }
}
