<?php

namespace App\Controller;

use App\Entity\DonationInitiative;
use App\Entity\FeeAdjustment;
use App\Form\DonationInitiativeType;
use App\Repository\FeeAdjustmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las iniciativas a las que lxs socixs destinan lo que pagan de más (las cestas para
 * la RSP, por ejemplo): cuáles hay, quién aporta a cada una y cuánto suman al mes.
 *
 * Ver, el permiso de lectura de socixs; crear o cambiar una, el de edición.
 */
#[Route('/gestion/partner/initiatives')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DonationInitiativeController extends AbstractController
{
    /** Todas, con lo que reciben este mes. */
    #[Route('', name: 'donation_initiative_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em, FeeAdjustmentRepository $adjustments): Response
    {
        $month = FeeAdjustment::month(new \DateTimeImmutable('today'));

        return $this->render('donation_initiative/index.html.twig', [
            'initiatives' => $em->getRepository(DonationInitiative::class)->findBy([], ['open' => 'DESC', 'name' => 'ASC']),
            'totals' => $adjustments->totalsByInitiative($month),
            'month' => $month,
        ]);
    }

    /** Una iniciativa: quién aporta este mes, cuánto y desde cuándo. */
    #[Route('/{id}', name: 'donation_initiative_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(DonationInitiative $initiative, FeeAdjustmentRepository $adjustments): Response
    {
        $month = FeeAdjustment::month(new \DateTimeImmutable('today'));
        $contributions = array_values(array_filter(
            $adjustments->findApplyingTo($month),
            static fn (FeeAdjustment $a): bool => $a->getInitiative() === $initiative,
        ));
        usort($contributions, static fn (FeeAdjustment $a, FeeAdjustment $b): int => strcmp($a->getPartner()->getLegalName(), $b->getPartner()->getLegalName()));

        return $this->render('donation_initiative/show.html.twig', [
            'initiative' => $initiative,
            'contributions' => $contributions,
            'total' => $adjustments->totalsByInitiative($month)[$initiative->getId()] ?? ['people' => 0, 'cents' => 0],
            'month' => $month,
        ]);
    }

    #[Route('/new', name: 'donation_initiative_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handle($request, new DonationInitiative(), $em, 'Iniciativa creada.');
    }

    #[Route('/{id}/edit', name: 'donation_initiative_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function edit(Request $request, DonationInitiative $initiative, EntityManagerInterface $em): Response
    {
        return $this->handle($request, $initiative, $em, 'Iniciativa guardada.');
    }

    /** Alta y cambio son el mismo formulario. */
    private function handle(Request $request, DonationInitiative $initiative, EntityManagerInterface $em, string $done): Response
    {
        $form = $this->createForm(DonationInitiativeType::class, $initiative);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($initiative);
            $em->flush();
            $this->addFlash('success', $done);

            return $this->redirectToRoute('donation_initiative_show', ['id' => $initiative->getId()]);
        }

        return $this->render('donation_initiative/form.html.twig', [
            'form' => $form,
            'initiative' => $initiative,
        ]);
    }
}
