<?php

namespace App\Controller;

use App\Entity\TransportRate;
use App\Entity\User;
use App\Entity\WeeklyBasketGroup;
use App\Form\TransportRateType;
use App\Repository\FeeRateRepository;
use App\Repository\TransportRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Poner y quitar el precio del transporte de un grupo de recogida. Se ve en la misma
 * pantalla que las tarifas ({@see FeeRateController::index}), porque es parte de la
 * cuota igual que ellas.
 *
 * Todo exige el permiso de edición de socixs (además del de la ruta: todo POST bajo
 * /gestion/partner lo exige).
 */
#[Route('/gestion/partner/fee-rates/transport')]
#[IsGranted('ROLE_GESTION_SOCIXS_EDIT')]
class TransportRateController extends AbstractController
{
    /**
     * Poner un precio de transporte desde una fecha. Desde la tabla llega con el grupo
     * ya elegido; la fecha propuesta es el día 1 del mes siguiente.
     */
    #[Route('/new', name: 'transport_rate_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TransportRateRepository $rates, FeeRateRepository $feeRates, EntityManagerInterface $em): Response
    {
        $group = $request->query->getInt('group') > 0 ? $em->find(WeeklyBasketGroup::class, $request->query->getInt('group')) : null;
        $form = $this->createForm(TransportRateType::class, [
            'pickupGroup' => $group,
            'validFrom' => new \DateTimeImmutable('first day of next month'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{pickupGroup: WeeklyBasketGroup, pricePerDelivery: string, validFrom: \DateTimeImmutable} $data */
            $data = $form->getData();
            if ($rates->existsFor($data['pickupGroup'], $data['validFrom'])) {
                $this->addFlash('warning', sprintf('%s ya tiene un precio de transporte desde el %s. Si está mal, quítalo antes.', $data['pickupGroup']->getName(), $data['validFrom']->format('d/m/Y')));
            } else {
                $em->persist(new TransportRate($data['pickupGroup'], $data['pricePerDelivery'], $data['validFrom'], $this->actor()));
                $em->flush();
                $this->addFlash('success', sprintf('Transporte a %s: %s € por cesta desde el %s.', $data['pickupGroup']->getName(), number_format((float) $data['pricePerDelivery'], 2, ',', '.'), $data['validFrom']->format('d/m/Y')));

                return $this->redirectToRoute('fee_rate_index');
            }
        }

        return $this->render('fee_rate/transport_new.html.twig', [
            'form' => $form,
            'group' => $group,
            'current' => $group !== null ? $feeRates->schedule()->transportRateOn($group, new \DateTimeImmutable('today')) : null,
        ]);
    }

    /**
     * Quitar un precio de transporte puesto por error. El anterior vuelve a valer
     * desde su fecha; para cambiar uno que está bien no se quita: se pone otro.
     */
    #[Route('/{id}/delete', name: 'transport_rate_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, TransportRate $rate, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-transport-rate-' . $rate->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('fee_rate_index');
        }

        $em->remove($rate);
        $em->flush();
        $this->addFlash('success', sprintf('Quitado el transporte a %s desde el %s.', $rate->getPickupGroup()->getName(), $rate->getValidFrom()->format('d/m/Y')));

        return $this->redirectToRoute('fee_rate_index');
    }

    private function actor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
