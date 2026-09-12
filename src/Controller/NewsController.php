<?php

namespace App\Controller;

use App\Service\AppSettings;
use App\Service\News\NewsAnnouncer;
use App\Service\News\NewsCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las novedades de la web: qué se ha añadido y cuándo se cuenta.
 *
 * DOS PANTALLAS PORQUE SON DOS PAPELES. La de socixs enseña lo ya anunciado; la
 * de administración enseña lo que está escrito y aún no se ha contado, con el
 * botón que lo cuenta. Separarlas es lo que hace que el botón signifique algo:
 * si la web publicara también lo pendiente, la novedad ya estaría contada a
 * quien pasara por ahí y anunciarla sería un trámite.
 *
 * EL CONTENIDO NO SE EDITA AQUÍ, y no es una carencia. Vive en `data/novedades.yaml`
 * y se escribe en el mismo commit que hace la feature ({@see NewsCatalog}
 * explica por qué). Esta pantalla decide el CUÁNDO, que es la parte que le toca
 * a la asociación.
 *
 * `IS_AUTHENTICATED_REMEMBERED` en la pantalla de socixs, como la bandeja de
 * avisos y por el mismo motivo: con `_FULLY`, quien vuelve con la sesión
 * recordada no aterriza aquí sino que rebota al panel, sin error y sin rastro.
 * Está documentado en {@see NotificationController}.
 */
class NewsController extends AbstractController
{
    /**
     * Lo que se ha ido añadiendo a la web, de lo más reciente a lo más antiguo.
     *
     * @param NewsAnnouncer $announcer quién sabe qué está anunciado
     *
     * @return Response la página de novedades
     */
    #[Route('/novedades', name: 'news_index', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function index(NewsAnnouncer $announcer): Response
    {
        return $this->render('news/index.html.twig', [
            'entries' => $announcer->published(),
        ]);
    }

    /**
     * La pantalla de administración: qué queda por contar y el botón de
     * contarlo.
     *
     * @param NewsAnnouncer $announcer lo pendiente y lo anunciado
     * @param NewsCatalog   $catalog   el fichero completo
     *
     * @return Response la pantalla de gestión
     */
    #[Route('/gestion/novedades', name: 'news_manage', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function manage(NewsAnnouncer $announcer, NewsCatalog $catalog, AppSettings $settings): Response
    {
        return $this->render('news/manage.html.twig', [
            'pending' => $announcer->pending(),
            'in_flight' => $announcer->inFlight(),
            'published' => $announcer->published(),
            'announced_through' => $announcer->announcedThrough(),
            'latest_id' => $catalog->latestId(),
            // Si la tarea está apagada, lo pedido se queda esperando para
            // siempre y el botón parece no hacer nada. La pantalla lo dice en
            // vez de dejar que se descubra preguntando por qué no llegó.
            'task_enabled' => $settings->getBool(AppSettings::CRON_NEWS),
        ]);
    }

    /**
     * Pide que se cuente a lxs socixs todo lo que hay escrito.
     *
     * NO MANDA NADA AQUÍ, y es lo que hace que esta acción sea segura: deja
     * anotado hasta dónde se quiere contar y devuelve. El envío —~130 correos,
     * el mayor del sistema— lo hace después el planificador
     * ({@see \App\Command\AnnounceNewsCommand}), porque un lote así dentro de
     * una petición web se queda a medias por tiempo de ejecución y nadie sabe a
     * quién le llegó.
     *
     * POST y con token: escribe a toda la asociación, así que no puede
     * dispararse desde un enlace ni repetirse recargando la página.
     *
     * @param Request       $request   la petición, con el token
     * @param NewsAnnouncer $announcer quien lleva la cuenta de lo pedido
     *
     * @return Response redirección a la pantalla de gestión
     */
    #[Route('/gestion/novedades/anunciar', name: 'news_announce', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function announce(Request $request, NewsAnnouncer $announcer): Response
    {
        if (!$this->isCsrfTokenValid('announce_news', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'No se ha podido verificar la petición. Inténtalo de nuevo.');

            return $this->redirectToRoute('news_manage');
        }

        $entries = $announcer->request();

        if ([] === $entries) {
            $this->addFlash('warning', 'No había ninguna novedad pendiente de contar.');

            return $this->redirectToRoute('news_manage');
        }

        // Se dice que saldrá, no que ha salido: el envío lo hace el reloj en la
        // próxima pasada. Prometer aquí "ya está contado" haría que quien mira
        // el correo a los dos minutos creyera que algo falla.
        $this->addFlash('success', sprintf(
            '%s en camino. Saldrá a lxs socixs en la próxima hora: por correo, en su bandeja de avisos y al móvil.',
            1 === \count($entries) ? 'Novedad' : \count($entries) . ' novedades',
        ));

        return $this->redirectToRoute('news_manage');
    }
}
