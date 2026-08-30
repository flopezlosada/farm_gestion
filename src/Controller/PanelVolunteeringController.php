<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerOfferRepository;
use App\Repository\VolunteerSignupRepository;
use App\Service\Volunteering\VolunteerContributions;
use App\Service\Volunteering\VolunteerEventRecorder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El voluntariado visto por un socix: qué hace falta, a qué se ha apuntado y
 * cuánto lleva hecho.
 *
 * EL ORDEN DE LA PANTALLA ES EL DISEÑO. Primero lo que hace falta, con fecha y
 * plazas libres; el contador propio va después y en pequeño. Al revés —"llevas
 * 0 horas" arriba y grande— es un reproche sin salida, y un reproche que no se
 * puede resolver de un clic sólo consigue que la gente deje de entrar en la web.
 * Y si dejan de entrar, se pierde también el canal por el que gestionan su
 * cesta, que es el activo de verdad.
 *
 * LO QUE OCURRE EN SU PUNTO DE RECOGIDA VA PRIMERO. Quien recoge en La Cabrera
 * ya va a estar allí el viernes: pedirle media hora es la fricción más baja que
 * existe, y enterrar esa tarea bajo otras tres que le pillan a cuarenta
 * kilómetros es perder la única a la que iba a decir que sí.
 *
 * Todo bajo el toggle del módulo: apagado, ni el menú ni estas rutas existen.
 */
#[Route('/panel/voluntariado')]
#[IsGranted('ROLE_PARTNER')]
#[IsGranted('FEATURE_VOLUNTEERING')]
class PanelVolunteeringController extends AbstractController
{
    /** Cuántas tareas se enseñan de golpe. Una lista larga se lee como un muro y no se lee. */
    private const MAX_OFFERS = 8;

    /**
     * Lo que hace falta, lo que tengo comprometido y lo que llevo hecho.
     */
    #[Route('', name: 'panel_volunteering', methods: ['GET'])]
    public function index(
        VolunteerOfferRepository $offers,
        VolunteerSignupRepository $signups,
        VolunteerCategoryRepository $categories,
        VolunteerContributions $contributions,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $now = new \DateTime();
        [$from, $to] = $contributions->period();

        $mine = $contributions->forPartner($partner);
        $node = $this->nodeOf($partner);
        $mySignups = $signups->findUpcomingFor($partner, $now);
        $myOfferIds = array_map(
            static fn (VolunteerSignup $signup): ?int => $signup->getOffer()?->getId(),
            $mySignups
        );

        return $this->render('Panel/volunteering.html.twig', [
            'partner' => $partner,
            'offers' => $offers->findStillNeededFor($now, $node, $myOfferIds, self::MAX_OFFERS),
            // El id y no el nodo: la plantilla sólo necesita comparar, y pasarle
            // la entidad invita a navegar relaciones desde Twig.
            'my_node_id' => $node?->getId(),
            'my_signups' => $mySignups,
            // Lo que ya pasó y aún no ha dicho si hizo. Va arriba del todo en la
            // pantalla: es una pregunta concreta, con respuesta de un clic, y
            // hasta que no la conteste esas horas no las tiene nadie.
            'pending_confirmation' => $signups->findPendingConfirmationFor($partner, $now),
            // Qué hizo, no sólo cuánto: "6 h" no dice nada, "6 h: dos repartos y
            // una mañana de plantación" sí.
            'my_done' => $signups->findDoneFor($partner, $from, $to),
            'my_offer_ids' => $myOfferIds,
            'my_minutes' => $mine->minutes,
            // La mediana de quienes participan (no la media) y a quién se le
            // enseña. Las dos reglas viven en VolunteerContribution, que es
            // también quien las explica: aquí sólo se consultan, para que la home
            // y esta pantalla no puedan divergir.
            'median_minutes' => $mine->medianMinutes,
            'show_median' => $mine->showMedian(),
            'categories' => $categories->findActive(),
            // Ids y no entidades: comparar objetos Doctrine con el operador `in`
            // de Twig depende de la identidad de instancia y falla en cuanto una
            // de las dos listas viene de otra consulta.
            'my_category_ids' => array_map(
                static fn ($category) => $category->getId(),
                $partner->getVolunteerCategories()->toArray()
            ),
            'has_preferences' => !$partner->hasNoVolunteerPreferences(),
        ]);
    }

    /**
     * Apuntarse a una tarea, con los acompañantes que se traigan.
     */
    #[Route('/{id}/apuntarme', name: 'panel_volunteering_signup', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function signUp(
        Request $request,
        VolunteerOffer $offer,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $partner = $this->getUser()->getPartner();

        if (!$offer->isOpen()) {
            $this->addFlash('warning', 'Esa tarea ya no admite gente: o está cubierta o ya ha pasado.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $companions = $offer->isCompanionsAllowed()
            ? max(0, (int) $request->request->get('companions', 0))
            : 0;

        // Reapuntarse después de haberse dado de baja reutiliza la fila: la
        // unicidad (offer, partner) impide que haya dos, y crear una segunda
        // acabaría en un 500 en vez de en un "vale".
        $existing = $signups->findOneFor($offer, $partner);
        if (null !== $existing) {
            $existing->reopen()->setCompanions($companions);
            $events->forOffer($offer, VolunteerEvent::TYPE_SIGNUP, ['companions' => $companions, 'again' => true], $partner);
            $em->flush();
            $this->addFlash('success', 'Apuntadx otra vez. Gracias.');

            return $this->redirectToRoute('panel_volunteering');
        }

        try {
            $em->persist(
                (new VolunteerSignup())
                    ->setOffer($offer)
                    ->setPartner($partner)
                    ->setCompanions($companions)
                    ->setNotes(trim((string) $request->request->get('notes')) ?: null)
            );
            $events->forOffer($offer, VolunteerEvent::TYPE_SIGNUP, ['companions' => $companions], $partner);
            $em->flush();
            $this->addFlash('success', 'Apuntadx. Gracias por echar una mano.');
        } catch (UniqueConstraintViolationException) {
            // Doble clic, o el botón de atrás del navegador. Ya está apuntadx,
            // que es lo que quería: no es un error que deba ver.
            $this->addFlash('success', 'Ya estabas apuntadx a esa tarea.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Darse de baja de una tarea. No borra la inscripción: la marca, para que
     * quien coordina sepa que hay un hueco que volver a cubrir.
     */
    #[Route('/{id}/darme-de-baja', name: 'panel_volunteering_withdraw', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function withdraw(
        Request $request,
        VolunteerOffer $offer,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $signup = $signups->findOneFor($offer, $this->getUser()->getPartner());

        if (null !== $signup && !$signup->isCancelled()) {
            $signup->cancel();
            $events->forOffer($offer, VolunteerEvent::TYPE_WITHDRAW, null, $signup->getPartner());
            $em->flush();
            $this->addFlash('success', 'Te hemos quitado de esa tarea. Gracias por avisar.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * "Ya la he hecho" / "al final no fui": quien se apuntó confirma por su
     * cuenta lo que pasó, y ahí es cuando se le computan las horas.
     *
     * Que lo diga quien fue, y no gestión al cerrar la tarea, es lo que quita el
     * punto único de fallo. Si el contador dependiera de que administración
     * cierre cada tarea a mano, se olvidarían —y se van a olvidar— y el contador
     * se quedaría a cero para todo el mundo sin que nadie supiera por qué.
     *
     * ES AUTODECLARADO, y con eso basta hoy: el contador es privado y no da nada
     * a cambio, así que no hay ningún incentivo para inflarlo. El día que las
     * horas cuenten para algo (una cuota, un descuento), habrá que revisar esta
     * decisión — y por eso queda registrado quién lo confirmó
     * ({@see VolunteerSignup::$attendanceSource}): para poder revisarlo entonces
     * sin tener que rehacer el histórico.
     *
     * Gestión puede corregirlo después desde la pantalla de la tarea.
     */
    #[Route('/{id}/confirmar', name: 'panel_volunteering_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(
        Request $request,
        VolunteerOffer $offer,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $signup = $signups->findOneFor($offer, $this->getUser()->getPartner());

        if (null === $signup || $signup->isCancelled()) {
            $this->addFlash('warning', 'No constas apuntadx a esa tarea.');

            return $this->redirectToRoute('panel_volunteering');
        }

        // Confirmar antes de que la tarea ocurra no significa nada, y dejaría
        // computadas unas horas que todavía no ha hecho nadie.
        if ($offer->getStartsAt() > new \DateTime()) {
            $this->addFlash('warning', 'Esa tarea todavía no ha llegado.');

            return $this->redirectToRoute('panel_volunteering');
        }

        if ($request->request->getBoolean('attended')) {
            $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);
            $events->forOffer($offer, VolunteerEvent::TYPE_ATTENDED, ['minutes' => $signup->getCreditedMinutes(), 'role' => $signup->getRole()], $signup->getPartner());
            $em->flush();
            $this->addFlash('success', 'Anotado. Gracias por echar una mano.');
        } else {
            $signup->markAbsent(VolunteerSignup::SOURCE_SELF);
            $events->forOffer($offer, VolunteerEvent::TYPE_ABSENT, null, $signup->getPartner());
            $em->flush();
            $this->addFlash('success', 'Anotado, gracias por decirlo.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Elegir de qué avisar. Marcar categorías significa "avísame de esto"; no
     * marcar ninguna, "avísame de lo que sea sencillo". El texto de la pantalla
     * tiene que decirlo con esas palabras o el escalado de avisos miente.
     */
    // El formulario que llega aquí vive en «Avisos» ({@see \App\Controller\PanelController::notifications()}),
    // no en esta pantalla, así que se vuelve allí. La ruta se queda bajo
    // /panel/voluntariado y con el IsGranted del módulo: son preferencias DE
    // voluntariado y no deben poder tocarse con el módulo apagado.
    #[Route('/preferencias', name: 'panel_volunteering_preferences', methods: ['POST'])]
    public function preferences(
        Request $request,
        VolunteerCategoryRepository $categories,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_notifications');
        }

        $partner = $this->getUser()->getPartner();
        $chosen = array_map('intval', (array) $request->request->all('categories'));

        // El "no me avises de esto" es una salida aparte y no la ausencia de
        // categorías: no marcar ninguna significa "avísame de lo que sea
        // sencillo", que es lo contrario. Sin esta casilla, la única forma de
        // silenciar el voluntariado sería apagar los avisos del navegador
        // enteros, y con ellos los que sí interesan.
        $partner->setVolunteeringOptOut((bool) $request->request->get('opt_out'));

        // Se reconstruye la lista entera en vez de aplicar diferencias: son
        // media docena de casillas y así desmarcar todo funciona igual de bien
        // que marcar, sin un caso especial para la lista vacía.
        foreach ($partner->getVolunteerCategories()->toArray() as $current) {
            $partner->removeVolunteerCategory($current);
        }

        foreach ($categories->findActive() as $category) {
            if (\in_array($category->getId(), $chosen, true)) {
                $partner->addVolunteerCategory($category);
            }
        }

        $events->forPartner($partner, VolunteerEvent::TYPE_PREFERENCES_CHANGED, ['areas' => $chosen, 'opt_out' => $partner->isVolunteeringOptOut()]);
        $em->flush();
        $this->addFlash(
            'success',
            $partner->isVolunteeringOptOut()
                ? 'Guardado. No te avisaremos de voluntariado.'
                : 'Guardado. Te avisaremos de lo que has elegido.'
        );

        return $this->redirectToRoute('panel_notifications');
    }

    /**
     * El punto de recogida del socix, si lo tiene. De ahí sale el orden de la
     * lista.
     *
     * @param Partner $partner el socix
     */
    private function nodeOf(Partner $partner): ?Node
    {
        return $partner->getWeeklyBasketGroup()?->getNode();
    }

    /**
     * Mismo guardarraíl que el resto del panel: una cuenta sin Partner vinculado
     * no puede usarlo, y redirige en vez de explotar.
     */
    private function ensureReady(): ?RedirectResponse
    {
        $user = $this->getUser();

        if ($user && method_exists($user, 'getPartner') && null !== $user->getPartner()) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a admin que te vincule para usar el panel.');

        return $this->redirectToRoute('homepage');
    }
}
