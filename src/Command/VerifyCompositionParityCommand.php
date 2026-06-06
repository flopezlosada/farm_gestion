<?php

namespace App\Command;

use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Service\Delivery\EggDeliveryResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verificación de paridad del rediseño de composición: para cada entrega
 * compara los números que daba el método VIEJO del reparto (EggDeliveryResolver
 * al vuelo + ponderación ½ por modalidad hardcodeada) contra el NUEVO (sumar los
 * WeeklyBasketItem materializados). Cero discrepancias = el cambio no altera
 * ningún reparto.
 *
 * Herramienta de migración de un solo uso, no parte del runtime. Acotada por
 * fecha como el backfill.
 */
#[AsCommand(
    name: 'app:verify-composition-parity',
    description: 'Compara reparto VIEJO (derivado) vs NUEVO (ítems) entrega a entrega.'
)]
class VerifyCompositionParityCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EggDeliveryResolver $eggResolver,
        private readonly WeeklyBasketItemRepository $itemRepository,
        private readonly PartnerBasketShareRepository $shareRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Fecha mínima (YYYY-MM-DD).', '2026-04-10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');

        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->createQuery(
            'SELECT wb FROM ' . WeeklyBasket::class . ' wb JOIN wb.basket b WHERE b.date >= :from ORDER BY b.date ASC'
        )->setParameter('from', $from)->getResult();

        $itemMap = $this->itemRepository->componentAmountsFor($weeklyBaskets);

        $mismatches = [];
        $oldCestasTotal = 0.0;
        $newCestasTotal = 0.0;
        $oldDozensTotal = 0.0;
        $newDozensTotal = 0.0;

        foreach ($weeklyBaskets as $wb) {
            $basket = $wb->getBasket();
            $share = $wb->getPartnerBasketShare()
                ?? $this->shareRepository->findActiveForPartner($wb->getPartner(), $basket->getDate());

            $modality = $wb->getBasketShare()?->getId();

            // VIEJO: ponderación ½ por modalidad hardcodeada × amount.
            $oldCestas = (float) ($wb->getAmount() ?? 1) * match ($modality) {
                BasketShare::ID_ONLY_EGG => 0.0,
                4, 6, 7 => 0.5,
                default => 1.0,
            };
            // VIEJO: huevos = resolver al vuelo (como hacía byNode, sin forzar
            // solo-huevos); docenas desde eggAmount del share.
            $oldEggs = $share !== null && $this->eggResolver->delivers($share, $basket);
            $oldDozens = $oldEggs ? (float) ($share?->getEggAmount()?->getDozens() ?? 0.0) : 0.0;

            // NUEVO: leer los ítems materializados.
            $items = $itemMap[$wb->getId()] ?? [];
            $newCestas = $items[BasketComponent::ID_VEGETABLES] ?? 0.0;
            $newEggs = isset($items[BasketComponent::ID_EGGS]);
            $newDozens = $items[BasketComponent::ID_EGGS] ?? 0.0;

            $oldCestasTotal += $oldCestas;
            $newCestasTotal += $newCestas;
            $oldDozensTotal += $oldDozens;
            $newDozensTotal += $newDozens;

            $diffs = [];
            if (abs($oldCestas - $newCestas) > 0.001) {
                $diffs[] = sprintf('cestas %.2f→%.2f', $oldCestas, $newCestas);
            }
            if ($oldEggs !== $newEggs) {
                $diffs[] = sprintf('huevos? %s→%s', $oldEggs ? 'sí' : 'no', $newEggs ? 'sí' : 'no');
            }
            if (abs($oldDozens - $newDozens) > 0.001) {
                $diffs[] = sprintf('docenas %.2f→%.2f', $oldDozens, $newDozens);
            }

            if ($diffs !== []) {
                $mismatches[] = sprintf(
                    'WB#%d %s · %s · mod=%s · %s',
                    $wb->getId(),
                    $basket->getDate()->format('Y-m-d'),
                    $wb->getPartner()?->getNameForDelivery() ?? '?',
                    $modality ?? '?',
                    implode(', ', $diffs),
                );
            }
        }

        $io->title('Paridad composición: VIEJO (derivado) vs NUEVO (ítems)');
        $io->definitionList(
            ['Entregas revisadas' => count($weeklyBaskets)],
            ['Cestas viejo / nuevo' => sprintf('%.2f / %.2f', $oldCestasTotal, $newCestasTotal)],
            ['Docenas viejo / nuevo' => sprintf('%.2f / %.2f', $oldDozensTotal, $newDozensTotal)],
            ['Discrepancias' => count($mismatches)],
        );

        if ($mismatches === []) {
            $io->success('PARIDAD TOTAL: el reparto nuevo coincide con el viejo en todas las entregas.');
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d entregas difieren. Primeras 30:', count($mismatches)));
        $io->listing(array_slice($mismatches, 0, 30));
        return Command::FAILURE;
    }
}
