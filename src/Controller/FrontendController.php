<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Node;
use App\Repository\BookingRepository;
use App\Repository\NodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Páginas públicas del frontend que necesitan datos de BBDD. Las puramente
 * estáticas se siguen sirviendo con TemplateController desde
 * config/routes/frontend.yml.
 */
class FrontendController extends AbstractController
{
    /**
     * Página pública "Hazte socix" (/socio).
     *
     * Pasa a la plantilla los horarios públicos de los nodos de recogida
     * indexados por nombre interno (UNIQUE en BBDD), para que la sección de
     * puntos de Madrid los pinte desde el modelo en vez de hardcodearlos
     * (punto 8 de la reunión de admin del 2026-06-11). Si un nodo no tiene
     * horario (NULL) la plantilla simplemente no lo muestra.
     *
     * @param NodeRepository $nodes Repositorio de nodos físicos de reparto.
     * @return Response
     */
    public function socios(NodeRepository $nodes): Response
    {
        $all = $nodes->findAll();
        $schedules = array_combine(
            array_map(static fn (Node $node) => $node->getName(), $all),
            array_map(static fn (Node $node) => $node->getSchedule(), $all),
        );

        return $this->render('frontend/socios.html.twig', [
            'schedules' => $schedules,
        ]);
    }

    /**
     * Página pública "Asambleas y eventos" (/asambleas).
     *
     * Sustituye al calendario externo de disroot: la agenda se pinta desde
     * los eventos públicos (Booking) que gestiona comunicación en
     * /gestion/eventos. Solo se listan los que aún no han pasado.
     *
     * @param BookingRepository $bookings Repositorio de eventos públicos.
     * @return Response
     */
    public function asambleas(BookingRepository $bookings): Response
    {
        return $this->render('frontend/asambleas.html.twig', [
            'events' => $bookings->findUpcoming(),
        ]);
    }

    /**
     * Detalle público de un evento (/evento/{id}).
     *
     * Es la versión anónima de booking_show (que vive tras el login de
     * gestión): cartel, fechas y contenido completo del evento.
     *
     * @param Booking $booking Evento resuelto por el param converter.
     * @return Response
     */
    public function evento(Booking $booking): Response
    {
        return $this->render('frontend/evento.html.twig', [
            'event' => $booking,
        ]);
    }
}
