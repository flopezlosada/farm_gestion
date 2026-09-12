<?php

namespace App\Controller;

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
    public function manage(NewsAnnouncer $announcer, NewsCatalog $catalog): Response
    {
        return $this->render('news/manage.html.twig', [
            'pending' => $announcer->pending(),
            'published' => $announcer->published(),
            'announced_through' => $announcer->announcedThrough(),
            'latest_id' => $catalog->latestId(),
        ]);
    }

    /**
     * Cuenta a lxs socixs todo lo que estaba pendiente.
     *
     * POST y con token: esto escribe a toda la asociación, así que no puede
     * dispararse desde un enlace ni repetirse recargando la página.
     *
     * @param Request       $request   la petición, con el token
     * @param NewsAnnouncer $announcer quien manda los avisos
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

        $result = $announcer->announce();

        if ([] === $result['entries']) {
            $this->addFlash('warning', 'No había ninguna novedad pendiente de contar.');

            return $this->redirectToRoute('news_manage');
        }

        // Los tres números por separado porque son tres alcances muy distintos:
        // el correo llega a casi toda la asociación, la bandeja sólo a quien
        // tiene cuenta y el móvil sólo a quien además activó los avisos. Un
        // único "avisadas N personas" haría pensar que ha llegado a mucha más
        // gente de la que ha llegado.
        $this->addFlash('success', sprintf(
            '%s contada%s: %d por correo, %d en la bandeja de avisos y %d al móvil.',
            1 === \count($result['entries']) ? 'Novedad' : \count($result['entries']) . ' novedades',
            1 === \count($result['entries']) ? '' : 's',
            $result['email'],
            $result['inbox'],
            $result['push'],
        ));

        return $this->redirectToRoute('news_manage');
    }
}
