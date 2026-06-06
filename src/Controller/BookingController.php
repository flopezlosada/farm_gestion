<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Form\BookingType;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/booking")]
#[IsGranted('ROLE_BLOG')]
class BookingController extends AbstractController
{

    #[Route("/calendar", name: "booking_calendar", methods: ["GET"])]
    public function calendar(): Response
    {
        return $this->render('booking/calendar.html.twig');
    }

    #[Route("/", name: "booking_index", methods: ["GET"])]
    public function index(BookingRepository $bookingRepository): Response
    {
        return $this->render('booking/index.html.twig', [
            'bookings' => $bookingRepository->findAll(),
        ]);
    }

    #[Route("/new", name: "booking_new", methods: ["GET","POST"])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $entity = new Booking();
        $form = $this->createForm(BookingType::class, $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity->setBeginAt(new \DateTime($entity->getBeginAt()));
            $entity->setEndAt(new \DateTime($entity->getEndAt()));
            $entityManager->persist($entity);
            $entityManager->flush();

            return $this->redirectToRoute('booking_index');
        }

        return $this->render('booking/new.html.twig', [
            'entity' => $entity,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "booking_show", methods: ["GET"])]
    public function show(Booking $booking): Response
    {
        return $this->render('booking/show.html.twig', [
            'booking' => $booking,
        ]);
    }

    #[Route("/{id}/edit", name: "booking_edit", methods: ["GET","POST"])]
    public function edit(Request $request, Booking $booking, EntityManagerInterface $entityManager): Response
    {
        if ($booking->getBeginAt() instanceof \DateTimeInterface) {
            $booking->setBeginAt($booking->getBeginAt()->format('Y-m-d'));
        }
        if ($booking->getEndAt() instanceof \DateTimeInterface) {
            $booking->setEndAt($booking->getEndAt()->format('Y-m-d'));
        }
        $form = $this->createForm(BookingType::class, $booking);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($booking->getBeginAt()) {
                $booking->setBeginAt(new \DateTime($booking->getBeginAt()));
            }
            if ($booking->getEndAt()) {
                $booking->setEndAt(new \DateTime($booking->getEndAt()));
            }
            $entityManager->flush();

            return $this->redirectToRoute('booking_index');
        }

        return $this->render('booking/edit.html.twig', [
            'booking' => $booking,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "booking_delete", methods: ["DELETE"])]
    public function delete(Request $request, Booking $booking, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$booking->getId(), $request->request->get('_token'))) {
            $entityManager->remove($booking);
            $entityManager->flush();
        }

        return $this->redirectToRoute('booking_index');
    }




}
