<?php

namespace App\Tests\Controller;

use App\Entity\CronRun;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las dos rutas del planificador vistas desde fuera, que es lo que no puede
 * comprobar un test del controlador suelto: que NO caen dentro del login.
 *
 * Un reloj externo no tiene cuenta en la aplicación ni puede tenerla. Si una
 * regla de seguridad las metiera detrás del firewall, la llamada acabaría en un
 * 302 al formulario de acceso y el planificador no correría nunca — con el
 * agravante de que un 302 no es un error, así que el servicio que llama daría el
 * tick por bueno y no avisaría de nada.
 */
class CronRoutesTest extends WebTestCase
{
    /**
     * El chequeo de salud es anónimo y de sólo lectura: responde en JSON sin
     * pedir credenciales.
     */
    public function testLaSaludEsPublicaYDevuelveJson(): void
    {
        $client = static::createClient();
        $this->clearRuns($client);

        $client->request('GET', '/cron/health');
        $response = $client->getResponse();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Sin ejecuciones registradas no hay nada que se haya pasado de plazo.');
        $this->assertJson((string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['late']);
    }

    /**
     * Y responde 503 cuando una tarea encendida se ha pasado de su plazo, que es
     * la señal que un monitor externo convierte en un aviso por correo. Es la
     * pieza que faltaba en julio: entonces nada gritó durante dos semanas.
     */
    public function testLaSaludAvisaConUn503CuandoUnaTareaSePasaDePlazo(): void
    {
        $client = static::createClient();
        $em = $this->clearRuns($client);

        // El congelado semanal viene encendido por defecto; una ejecución de
        // hace tres meses lo deja muy fuera de su plazo de ocho días.
        $em->persist(
            (new CronRun())
                ->setTaskKey(AppSettings::CRON_GENERATE_WEEKLY_DELIVERY)
                ->setCommand('app:generate-weekly-delivery')
                ->setStatus(CronRun::STATUS_DONE)
                ->setStartedAt(new \DateTimeImmutable('-90 days'))
                ->setFinishedAt(new \DateTimeImmutable('-90 days'))
        );
        $em->flush();

        $client->request('GET', '/cron/health');

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($payload['ok']);
        $this->assertContains(AppSettings::CRON_GENERATE_WEEKLY_DELIVERY, $payload['late']);

        $this->clearRuns($client);
    }

    /**
     * El tick sin token responde 404, no una redirección al login: para quien no
     * traiga el token, esa URL sencillamente no existe.
     */
    public function testElTickSinTokenNoExisteYNoRedirigeAlLogin(): void
    {
        $client = static::createClient();

        $client->request('POST', '/cron/tick');

        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /**
     * Vacía el registro de ejecuciones y devuelve el EntityManager.
     *
     * El cliente se crea SIEMPRE antes de tocar el contenedor: pedirlo primero
     * arranca el kernel y entonces createClient() revienta con "kernel should
     * only be booted once".
     *
     * @param KernelBrowser $client Cliente ya creado.
     */
    private function clearRuns(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(CronRun::class)->findAll() as $run) {
            $em->remove($run);
        }
        $em->flush();

        return $em;
    }
}
