<?php

namespace App\Controller;

use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use App\Entity\User;
use App\Form\FeeRateType;
use App\Repository\FeeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los precios base de las cuotas: cuánto cuesta al mes cada cesta de verdura y cada
 * docena de huevos, para todo el mundo, y desde cuándo.
 *
 * Ver, el permiso de lectura de socixs; poner o quitar un precio, el de edición
 * (además del de la ruta: todo POST bajo /gestion/partner lo exige).
 */
#[Route('/gestion/partner/fee-rates')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class FeeRateController extends AbstractController
{
    /** Lo que vale hoy cada concepto, lo que ya está programado y el historial. */
    #[Route('', name: 'fee_rate_index', methods: ['GET'])]
    public function index(FeeRateRepository $rates): Response
    {
        $today = new \DateTimeImmutable('today');
        $schedule = $rates->schedule();
        $row = static function (FeeConcept $c) use ($schedule, $today): array {
            $current = $schedule->rateOn($c, $today);

            return [
                'concept' => $c,
                'current' => $current,
                'next' => $schedule->nextAfter($c, $today),
            ];
        };

        $history = array_merge(...array_map(static fn (FeeConcept $c): array => $schedule->history($c), FeeConcept::cases()));
        usort($history, static fn (FeeRate $a, FeeRate $b): int => [$b->getValidFrom(), $b->getId()] <=> [$a->getValidFrom(), $a->getId()]);

        return $this->render('fee_rate/index.html.twig', [
            'groups' => array_map(static fn (array $concepts): array => array_map($row, $concepts), FeeConcept::grouped()),
            'missing' => $schedule->missingOn($today),
            'history' => $history,
            'today' => $today,
        ]);
    }

    /**
     * Poner un precio nuevo desde una fecha. Desde la tabla llega con el concepto ya
     * elegido; la fecha propuesta es el día 1 del mes siguiente, que es cuando suele
     * cambiar una cuota.
     */
    #[Route('/new', name: 'fee_rate_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function new(Request $request, FeeRateRepository $rates, EntityManagerInterface $em): Response
    {
        $concept = FeeConcept::tryFrom((string) $request->query->get('concept'));
        $form = $this->createForm(FeeRateType::class, [
            'concept' => $concept,
            'validFrom' => new \DateTimeImmutable('first day of next month'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{concept: FeeConcept, price: string, validFrom: \DateTimeImmutable} $data */
            $data = $form->getData();
            if ($rates->existsFor($data['concept'], $data['validFrom'])) {
                $this->addFlash('warning', sprintf('%s ya tiene un precio desde el %s. Si está mal, quítalo antes.', $data['concept']->label(), $data['validFrom']->format('d/m/Y')));
            } else {
                $em->persist(new FeeRate($data['concept'], $data['price'], $data['validFrom'], $this->actor()));
                $em->flush();
                $this->addFlash('success', sprintf('%s: %s € desde el %s.', $data['concept']->label(), number_format((float) $data['price'], 2, ',', '.'), $data['validFrom']->format('d/m/Y')));

                return $this->redirectToRoute('fee_rate_index');
            }
        }

        $schedule = $rates->schedule();

        return $this->render('fee_rate/new.html.twig', [
            'form' => $form,
            'concept' => $concept,
            'current' => $concept !== null ? $schedule->rateOn($concept, new \DateTimeImmutable('today')) : null,
        ]);
    }

    /**
     * Quitar un precio puesto por error. El anterior vuelve a valer desde su fecha;
     * para cambiar un precio que está bien no se quita: se pone otro.
     */
    #[Route('/{id}/delete', name: 'fee_rate_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
    public function delete(Request $request, FeeRate $rate, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-fee-rate-' . $rate->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('fee_rate_index');
        }

        $em->remove($rate);
        $em->flush();
        $this->addFlash('success', sprintf('Quitado el precio de %s desde el %s.', mb_strtolower($rate->getConcept()->label()), $rate->getValidFrom()->format('d/m/Y')));

        return $this->redirectToRoute('fee_rate_index');
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
