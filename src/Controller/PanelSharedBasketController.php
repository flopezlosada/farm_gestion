<?php

namespace App\Controller;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\SharedBasketChangeRequest;
use App\Repository\BasketRepository;
use App\Repository\BasketShareRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\SharedBasketChangeRequestRepository;
use App\Repository\WeeklyBasketGroupRepository;
use App\Service\Delivery\SharedBasketChangeMediator;
use App\Service\Delivery\SharedPairDeliveryEditor;
use App\Service\Delivery\SharedPairException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los cambios que los dos hogares de una cesta compartida se piden entre sí.
 *
 * PANTALLA APARTE Y NO UN TROZO DEL CALENDARIO porque lo que se hace aquí no es
 * mover una cesta, es contestar a alguien: lo que se lee es quién pide qué y desde
 * cuándo espera. El gesto de PEDIR un cambio de día sí vive en el calendario, que es
 * donde están los días a los que se puede mover; lo que llega aquí es el acuerdo.
 *
 * Toda la lógica —quién puede pedir, qué se valida, cuándo se aplica y a quién se
 * avisa— vive en {@see SharedBasketChangeMediator}. Aquí sólo hay sesión, CSRF y
 * mensajes.
 */
#[Route('/panel/shared-basket')]
#[IsGranted('ROLE_PARTNER')]
#[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
class PanelSharedBasketController extends AbstractController
{
    /**
     * Lo que hay entre los dos hogares: lo que me toca contestar y lo que pedí yo.
     *
     * @param SharedBasketChangeRequestRepository $requests Peticiones vivas.
     * @param SharedPairDeliveryEditor            $editor   Para resolver el otro hogar.
     * @param BasketShareRepository               $modalities Catálogo de modalidades.
     *
     * @return Response La pantalla.
     */
    #[Route('', name: 'panel_shared_basket', methods: ['GET'])]
    public function index(
        SharedBasketChangeRequestRepository $requests,
        SharedPairDeliveryEditor $editor,
        BasketShareRepository $modalities,
        PartnerBasketShareRepository $shares,
    ): Response {
        $partner = $this->partner();
        if (null === $partner) {
            return $this->redirectToRoute('panel');
        }

        try {
            $other = $editor->counterpart($partner);
        } catch (SharedPairException $e) {
            // Quien no comparte cesta no tiene nada que hacer aquí, y tampoco es un
            // error suyo: se le devuelve al panel con el motivo.
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('panel');
        }

        // LA ÚLTIMA CESTA CONTRATADA, NO LA QUE SE RECOGE HOY. Un cambio de modalidad
        // entra en vigor a principio de mes, así que entre que administración lo aplica y
        // que empieza pasan semanas. Preguntando por la de hoy, el acuerdo seguía
        // saliendo como "en manos de administración" todo ese tiempo —con el cambio ya
        // hecho— y de paso les impedía pedir ninguna otra cosa. Es el mismo finder que
        // usa el cambio de modalidad para saber qué cesta sustituye.
        $latest = $shares->findLatestActiveForPartner($partner);
        $current = $latest?->getBasketShare()?->getId();

        // Cesta ya contratada que aún no ha empezado: es lo que hay que poder leer cuando
        // el cartel de "falta aplicarlo" desaparece. Sin esto, quien entra a ver en qué
        // quedó aquello no encuentra nada.
        $startsOn = $latest?->getStartDate();
        $upcoming = $startsOn instanceof \DateTimeInterface && $startsOn > new \DateTime('today')
            ? $latest
            : null;

        return $this->render('Panel/shared_basket.html.twig', [
            'other' => $other,
            'upcoming' => $upcoming,
            'incoming' => $requests->findPendingFor($partner),
            'outgoing' => $requests->findPendingFrom($partner),
            // Lo contestado en las últimas semanas: es donde aterriza quien abre el aviso
            // de "no puedo con ese cambio". Sin esto llegaría a una pantalla vacía.
            'decided' => $requests->findDecidedBetween($partner, $other, new \DateTimeImmutable('-30 days')),
            // Acuerdos de modalidad que administración aún no ha aplicado: siguen VIVOS, así
            // que ni son historia ni dejan proponer otra cosa encima.
            'agreed' => $requests->findAgreedModalityPending($partner, $other, $current),
            // Sólo modalidades COMPARTIDAS: una pareja que se pasa a cesta entera deja
            // de ser pareja, y eso no es un cambio de modalidad sino otra conversación.
            // Y sin la que YA tienen: proponer la de siempre no es un cambio, y quien la
            // eligiera se llevaría un "acuerdo" que no mueve nada.
            'modalities' => array_values(array_filter(
                $modalities->findBy(['id' => BasketShare::IDS_SHARED], ['id' => 'ASC']),
                static fn (BasketShare $modality): bool => $modality->getId() !== $current,
            )),
        ]);
    }

    /**
     * Pide al otro hogar cambiar de día el reparto de una semana. Lo lanza el
     * calendario, que es de donde sale el día destino.
     *
     * @param Request                    $request  Lleva basket_id, to_basket_id, nota y CSRF.
     * @param BasketRepository           $baskets  Semanas.
     * @param SharedBasketChangeMediator $mediator Quien gobierna las peticiones.
     *
     * @return RedirectResponse De vuelta al calendario.
     */
    #[Route('/request/move', name: 'panel_shared_basket_request_move', methods: ['POST'])]
    public function requestMove(
        Request $request,
        BasketRepository $baskets,
        SharedBasketChangeMediator $mediator,
    ): RedirectResponse {
        $partner = $this->partner();
        if (null === $partner || !$this->isCsrfTokenValid('panel_shared_basket_request', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_calendar');
        }

        $from = $baskets->find((int) $request->request->get('basket_id', 0));
        $to = $baskets->find((int) $request->request->get('to_basket_id', 0));
        if (null === $from) {
            $this->addFlash('error', 'Elige la semana que quieres cambiar.');

            return $this->redirectToRoute('panel_calendar');
        }

        try {
            $created = $mediator->requestMove($partner, $from, $to, $request->request->get('note'));
        } catch (SharedPairException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel_calendar', [
                'year' => $from->getDate()?->format('Y'),
                'month' => $from->getDate()?->format('n'),
                'sel' => $from->getId(),
            ]);
        }

        $this->addFlash('notice', sprintf(
            'Se lo hemos pedido a %s. La cesta no cambia hasta que conteste; te avisaremos.',
            $created->getCounterpart()?->getNameForDelivery() ?? 'el otro hogar',
        ));

        return $this->redirectToRoute('panel_shared_basket');
    }

    /**
     * Pide al otro hogar recoger una semana en otro punto.
     *
     * @param Request                     $request Lleva basket_id, group_id, nota y CSRF.
     * @param BasketRepository            $baskets Semanas.
     * @param WeeklyBasketGroupRepository $groups  Puntos de recogida.
     * @param SharedBasketChangeMediator  $mediator
     *
     * @return RedirectResponse
     */
    #[Route('/request/relocate', name: 'panel_shared_basket_request_relocate', methods: ['POST'])]
    public function requestRelocate(
        Request $request,
        BasketRepository $baskets,
        WeeklyBasketGroupRepository $groups,
        SharedBasketChangeMediator $mediator,
    ): RedirectResponse {
        $partner = $this->partner();
        if (null === $partner || !$this->isCsrfTokenValid('panel_shared_basket_request', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel');
        }

        $basket = $baskets->find((int) $request->request->get('basket_id', 0));
        $group = $groups->find((int) $request->request->get('group_id', 0));
        if (null === $basket || null === $group) {
            $this->addFlash('error', 'Elige la semana y el punto de recogida.');

            return $this->redirectToRoute('panel');
        }

        try {
            $created = $mediator->requestRelocate($partner, $basket, $group, $request->request->get('note'));
        } catch (SharedPairException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel');
        }

        $this->addFlash('notice', sprintf(
            'Se lo hemos pedido a %s. El punto no cambia hasta que conteste; te avisaremos.',
            $created->getCounterpart()?->getNameForDelivery() ?? 'el otro hogar',
        ));

        return $this->redirectToRoute('panel_shared_basket');
    }

    /**
     * Pide al otro hogar cambiar la modalidad o el turno de la cesta.
     *
     * Aceptarla no la aplica: queda acordada y la aplica administración, que es quien
     * lleva la cuota. El formulario recoge lo que quieren y el recado.
     *
     * @param Request                    $request  Lleva basket_share_id, nota y CSRF.
     * @param SharedBasketChangeMediator $mediator
     *
     * @return RedirectResponse
     */
    #[Route('/request/modality', name: 'panel_shared_basket_request_modality', methods: ['POST'])]
    public function requestModality(Request $request, SharedBasketChangeMediator $mediator): RedirectResponse
    {
        $partner = $this->partner();
        if (null === $partner || !$this->isCsrfTokenValid('panel_shared_basket_request', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_shared_basket');
        }

        $modalityId = (int) $request->request->get('basket_share_id', 0);
        if (!in_array($modalityId, BasketShare::IDS_SHARED, true)) {
            $this->addFlash('error', 'Elige una modalidad de cesta compartida.');

            return $this->redirectToRoute('panel_shared_basket');
        }

        try {
            $created = $mediator->requestModality(
                $partner,
                ['basket_share_id' => $modalityId],
                $request->request->get('note'),
            );
        } catch (SharedPairException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel_shared_basket');
        }

        $this->addFlash('notice', sprintf(
            'Se lo hemos pedido a %s. Si dice que sí, lo aplicará administración.',
            $created->getCounterpart()?->getNameForDelivery() ?? 'el otro hogar',
        ));

        return $this->redirectToRoute('panel_shared_basket');
    }

    /**
     * Acepta la petición del otro hogar. Aquí es donde el reparto cambia.
     *
     * @param SharedBasketChangeRequest  $changeRequest Petición (resuelta por id).
     * @param Request                    $request       Lleva el CSRF.
     * @param SharedBasketChangeMediator $mediator
     *
     * @return RedirectResponse
     */
    #[Route('/{id}/accept', name: 'panel_shared_basket_accept', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function accept(
        SharedBasketChangeRequest $changeRequest,
        Request $request,
        SharedBasketChangeMediator $mediator,
    ): RedirectResponse {
        return $this->decide($changeRequest, $request, function (Partner $partner) use ($mediator, $changeRequest): string {
            $mediator->accept($changeRequest, $partner);

            return SharedBasketChangeRequest::KIND_MODALITY === $changeRequest->getKind()
                ? 'Hecho. Queda acordado entre los dos y lo aplicará administración.'
                : 'Hecho: el cambio ya está aplicado para los dos.';
        });
    }

    /**
     * Rechaza la petición del otro hogar.
     *
     * @param SharedBasketChangeRequest  $changeRequest Petición.
     * @param Request                    $request       Lleva el CSRF.
     * @param SharedBasketChangeMediator $mediator
     *
     * @return RedirectResponse
     */
    #[Route('/{id}/reject', name: 'panel_shared_basket_reject', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reject(
        SharedBasketChangeRequest $changeRequest,
        Request $request,
        SharedBasketChangeMediator $mediator,
    ): RedirectResponse {
        return $this->decide($changeRequest, $request, function (Partner $partner) use ($mediator, $changeRequest): string {
            $mediator->reject($changeRequest, $partner);

            return 'Contestado: la cesta se queda como estaba y se lo hemos dicho.';
        });
    }

    /**
     * Retira una petición propia que todavía no ha contestado nadie.
     *
     * @param SharedBasketChangeRequest  $changeRequest Petición.
     * @param Request                    $request       Lleva el CSRF.
     * @param SharedBasketChangeMediator $mediator
     *
     * @return RedirectResponse
     */
    #[Route('/{id}/cancel', name: 'panel_shared_basket_cancel', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function cancel(
        SharedBasketChangeRequest $changeRequest,
        Request $request,
        SharedBasketChangeMediator $mediator,
    ): RedirectResponse {
        return $this->decide($changeRequest, $request, function (Partner $partner) use ($mediator, $changeRequest): string {
            $mediator->cancel($changeRequest, $partner);

            return 'Retirada. No hemos molestado a nadie más con ella.';
        });
    }

    /**
     * El esqueleto común de las tres respuestas: sesión, CSRF, ejecutar y contar qué
     * ha pasado. Lo que cambia entre ellas cabe en una línea, así que la guardia no se
     * escribe tres veces —que es como una de las tres se queda sin ella—.
     *
     * @param SharedBasketChangeRequest $changeRequest La petición sobre la que se actúa.
     * @param Request                   $request       La petición HTTP, con el CSRF.
     * @param callable(Partner): string $action        Qué hacer; devuelve el mensaje de éxito.
     *
     * @return RedirectResponse Siempre de vuelta a la pantalla.
     */
    private function decide(SharedBasketChangeRequest $changeRequest, Request $request, callable $action): RedirectResponse
    {
        $partner = $this->partner();
        if (null === $partner || !$this->isCsrfTokenValid('panel_shared_basket_decide', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_shared_basket');
        }

        try {
            $this->addFlash('notice', $action($partner));
        } catch (SharedPairException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\LogicException $e) {
            // Motivos del motor de reparto (traslado que choca con un cambio de día,
            // por ejemplo): ya vienen escritos para leerse.
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_shared_basket');
    }

    /**
     * El socix de la sesión, o null si la cuenta no está vinculada a ninguno.
     *
     * @return Partner|null El socix.
     */
    private function partner(): ?Partner
    {
        $user = $this->getUser();

        return $user && method_exists($user, 'getPartner') ? $user->getPartner() : null;
    }
}
