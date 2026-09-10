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
