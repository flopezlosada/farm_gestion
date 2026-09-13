<?php

namespace App\Tests\Controller;

use App\Entity\NotificationLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La pantalla del registro de avisos. Se comprueba lo que hace falta para que
 * sirva como herramienta de diagnóstico: que las dos pestañas cargan, que los
 * filtros filtran de verdad —no que se pinten— y que no la ve quien no debe.
 */
class NotificationLogControllerTest extends AbstractAuthenticatedTest
{
    /** Las dos pestañas responden y se pintan enteras. */
    public function testLasDosPestanasCargan(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/avisos');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/gestion/avisos/tareas');
        $this->assertResponseIsSuccessful();
    }

    /**
     * El filtro de búsqueda deja fuera lo que no coincide. Es LA función de la
     * pantalla: llega alguien diciendo que no le llega nada, escribes su correo
     * y tienes que ver lo suyo y sólo lo suyo.
     */
    public function testLaBusquedaDejaFueraLoQueNoCoincide(): void
    {
        $client = $this->createAuthenticatedClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $sufijo = uniqid();
        $buscada = sprintf('buscada-%s@test.org', $sufijo);
        $otra = sprintf('otra-%s@test.org', $sufijo);

        $logs = [
            $this->log($buscada, 'pickup_reminder', 'Recordatorio: tu cesta'),
            $this->log($otra, 'pickup_reminder', 'Recordatorio: tu cesta'),
        ];
        foreach ($logs as $log) {
            $em->persist($log);
        }
        $em->flush();
        $ids = array_map(static fn (NotificationLog $l): ?int => $l->getId(), $logs);

        try {
            $crawler = $client->request('GET', '/gestion/avisos?q=' . urlencode($buscada));

            $this->assertResponseIsSuccessful();
            $html = $crawler->html();
            $this->assertStringContainsString($buscada, $html);
            $this->assertStringNotContainsString($otra, $html, 'La búsqueda no debe traer avisos de otra persona.');
        } finally {
            foreach ($ids as $id) {
                $this->borrar($id);
            }
        }
    }

    /**
     * El rango de fechas acota de verdad. Un registro que enseña todo siempre
     * no sirve para mirar un día concreto, que es como se diagnostica.
     */
    public function testElRangoDeFechasAcota(): void
    {
        $client = $this->createAuthenticatedClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $sufijo = uniqid();
        $antiguo = sprintf('antiguo-%s@test.org', $sufijo);

        $log = $this->log($antiguo, 'pickup_reminder', 'De hace mucho')
            ->setSentAt(new \DateTimeImmutable('-200 days'));
        $em->persist($log);
        $em->flush();
        $id = $log->getId();

        try {
            // Rango por defecto: los últimos 30 días, así que no debe salir.
            $reciente = $client->request('GET', '/gestion/avisos')->html();
            $this->assertStringNotContainsString($antiguo, $reciente);

            // Pidiendo un año hacia atrás, sí.
            $amplio = $client->request('GET', '/gestion/avisos?' . http_build_query([
                'from' => (new \DateTimeImmutable('-365 days'))->format('Y-m-d'),
                'to' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'q' => $antiguo,
            ]))->html();
            $this->assertStringContainsString($antiguo, $amplio);
        } finally {
            // Se RELEE por id en vez de reutilizar el objeto: cada petición del
            // cliente reinicia el kernel, así que la entidad que se persistió
            // arriba pertenece a un EntityManager que ya no existe y borrarla
            // directamente lanza "Detached entity cannot be removed".
            $this->borrar($id);
        }
    }

    /**
     * La ficha enseña el motivo del fallo ENTERO.
     *
     * Es la razón de existir de esa pantalla: en el listado el motivo no cabe
     * —son párrafos técnicos con rutas dentro— y estuvo recortado, con el texto
     * completo escondido en un `title` que en el móvil no existe. Si vuelve a
     * salir cortado, esto se pone en rojo.
     */
    public function testLaFichaEnsenaElMotivoEnteroDelFallo(): void
    {
        $client = $this->createAuthenticatedClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        // Largo (los motivos reales lo son) y SIN comillas ni & ni <: Twig los
        // escapa a entidades y la comparación sobre el HTML crudo fallaría por
        // el escapado, no por el recorte que es lo que se quiere vigilar.
        $motivo = 'No se pudo conectar con el servidor de correo tras varios intentos seguidos y el aviso se quedo sin salir.';
        $log = $this->log(sprintf('fallido-%s@test.org', uniqid()), 'news', 'Novedades')
            ->setStatus(NotificationLog::STATUS_FAILED)
            ->setError($motivo);
        $em->persist($log);
        $em->flush();
        $id = $log->getId();

        try {
            $html = $client->request('GET', '/gestion/avisos/' . $id)->html();

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString($motivo, $html, 'El motivo tiene que verse completo, no recortado.');
        } finally {
            $this->borrar($id);
        }
    }

    /**
     * Un correo cuya dirección no es de ningún socix se dice, en vez de dejar
     * el hueco en blanco: el registro recoge TODO lo que sale, y ahí hay avisos
     * a administración o a terceros que no son de nadie de la casa.
     */
    public function testLaFichaAvisaCuandoElCorreoNoEsDeNingunSocix(): void
    {
        $client = $this->createAuthenticatedClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $log = $this->log(sprintf('nadie-%s@test.org', uniqid()), 'coverage_alert', 'Huecos');
        $em->persist($log);
        $em->flush();
        $id = $log->getId();

        try {
            $html = $client->request('GET', '/gestion/avisos/' . $id)->html();

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('No corresponde a ninguna ficha de socix', $html);
        } finally {
            $this->borrar($id);
        }
    }

    /** Un id que no está en el registro es un 404, no una pantalla a medias. */
    public function testUnAvisoQueNoExisteDa404(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/avisos/999999999');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Las rutas hermanas siguen siendo suyas.
     *
     * La ficha cuelga de `/gestion/avisos/{id}`, justo donde ya vivían
     * `/tareas` y `/cobertura`. El requisito de que el id sea numérico es lo
     * único que impide que "tareas" entre por la ficha como si fuera un id.
     */
    public function testLasPestanasNoCaenEnLaFicha(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/gestion/avisos/cobertura');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            'notification_log_coverage',
            $client->getRequest()->attributes->get('_route'),
        );
    }

    /**
     * Quien no es administración no entra: el registro lleva direcciones de
     * correo de socixs y qué se les ha mandado.
     */
    public function testUnGestorSinAdminNoEntra(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));

        $client->request('GET', '/gestion/avisos');

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Borra una fila sembrada por su id, releyéndola con el EntityManager que
     * esté vivo AHORA. Cada `request()` del cliente reinicia el kernel, así que
     * el objeto que se persistió antes de la primera petición pertenece a un
     * gestor que ya se cerró y `remove()` lo rechaza por desligado.
     */
    private function borrar(?int $id): void
    {
        if ($id === null) {
            return;
        }

        $fresco = static::getContainer()->get('doctrine')->getManager();
        $log = $fresco->getRepository(NotificationLog::class)->find($id);
        if ($log !== null) {
            $fresco->remove($log);
            $fresco->flush();
        }
    }

    private function log(string $target, string $kind, string $subject): NotificationLog
    {
        return (new NotificationLog())
            ->setChannel(NotificationLog::CHANNEL_EMAIL)
            ->setKind($kind)
            ->setSubject($subject)
            ->setTarget($target)
            ->setStatus(NotificationLog::STATUS_SENT);
    }
}
