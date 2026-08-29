<?php

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Alta y baja del navegador de quien ha iniciado sesión en los avisos push.
 *
 * Lo llama `assets/js/push.js` por fetch, no un formulario, así que responde
 * JSON. Sin token CSRF: el navegador no manda aquí nada que valga de por sí —
 * un endpoint de push ajeno inyectado por un tercero sólo conseguiría que le
 * llegaran a esa persona nuestros propios avisos, y sin sesión iniciada no se
 * llega a entrar.
 *
 * NO se registra la suscripción de quien no ha iniciado sesión: un aviso tiene
 * que poder atribuirse a alguien o no hay a quién mandárselo.
 *
 * Inyecta {@see EntityManagerInterface} por parámetro en vez de tirar del
 * compat `getDoctrine()` de {@see AbstractAppController}: los controllers
 * nuevos nacen ya en la dirección a la que va el resto (sub-fase 8.6).
 */
#[IsGranted('ROLE_USER')]
class PushSubscriptionController extends AbstractController
{
    /**
     * La clave pública VAPID que el navegador necesita para suscribirse, o null
     * si el push no está configurado en este entorno.
     *
     * La pide el JS antes de enseñar nada: sin clave no tiene sentido ofrecer
     * "activar avisos" y que luego falle.
     */
    #[Route('/push/clave-publica', name: 'push_public_key', methods: ['GET'])]
    public function publicKey(
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        string $publicKey,
    ): JsonResponse {
        return new JsonResponse(['publicKey' => '' !== $publicKey ? $publicKey : null]);
    }

    /**
     * Registra este navegador. Si el endpoint ya estaba (misma persona u otra
     * que usó antes este equipo), se reemplaza: el endpoint identifica al
     * navegador, no a la persona.
     */
    #[Route('/push/suscribir', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        #[CurrentUser] User $user,
        PushSubscriptionRepository $subscriptions,
        EntityManagerInterface $entityManager,
    ): Response {
        $data = json_decode((string) $request->getContent(), true) ?? [];

        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['keys']['p256dh'] ?? '');
        $auth = (string) ($data['keys']['auth'] ?? '');

        if ('' === $endpoint || '' === $p256dh || '' === $auth) {
            return new JsonResponse(['error' => 'Faltan datos de la suscripción.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isTrustedPushEndpoint($endpoint)) {
            return new JsonResponse(['error' => 'Endpoint de push no reconocido.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $subscriptions->findOneByEndpoint($endpoint);
        if (null !== $existing) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        try {
            $entityManager->persist(
                (new PushSubscription())
                    ->setUser($user)
                    ->setEndpoint($endpoint)
                    ->setP256dh($p256dh)
                    ->setAuth($auth)
            );
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Dos pestañas suscribiéndose a la vez, o un reintento: la otra
            // ganó la carrera e insertó el mismo endpoint. La fila existe
            // igualmente, así que esto es un éxito y no un 500.
        }

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }

    /**
     * Da de baja este navegador. Idempotente: dar de baja algo que ya no está
     * es un éxito, no un error.
     */
    #[Route('/push/desuscribir', name: 'push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(
        Request $request,
        #[CurrentUser] User $user,
        PushSubscriptionRepository $subscriptions,
        EntityManagerInterface $entityManager,
    ): Response {
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $endpoint = (string) ($data['endpoint'] ?? '');

        $existing = '' !== $endpoint ? $subscriptions->findOneByEndpoint($endpoint) : null;

        // Sólo se borra lo propio: con el endpoint de otra persona, cualquiera
        // con sesión podría dejarla sin avisos.
        if (null !== $existing && $existing->getUser() === $user) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Si el endpoint es de un servicio de push de navegador conocido y va por
     * HTTPS. Lista cerrada a propósito: el servidor va a hacer POST a esta URL
     * tal cual venga, así que cualquier host fuera de estos se rechaza (defensa
     * contra SSRF).
     *
     * @param string $endpoint el endpoint que manda el navegador
     *
     * @return bool true si es un endpoint de push de confianza
     */
    private function isTrustedPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || 'https' !== $parts['scheme']) {
            return false;
        }

        $host = strtolower($parts['host']);

        return 'fcm.googleapis.com' === $host                    // Chrome / Android (FCM)
            || 'updates.push.services.mozilla.com' === $host     // Firefox (Mozilla autopush)
            || 'web.push.apple.com' === $host                    // Safari / iOS (Apple)
            || str_ends_with($host, '.notify.windows.com');      // Edge (Windows WNS)
    }
}
