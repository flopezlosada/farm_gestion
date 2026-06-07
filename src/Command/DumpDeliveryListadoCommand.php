<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vuelca el listado de reparto (persona × viernes) de un nodo a CSV, leyendo el
 * MODELO MATERIALIZADO por el MISMO camino que {@see \App\Service\Delivery\NodeDeliverySheet}
 * (repos findForNodeAndBasket + componentAmountsFor): cestas = componente verdura,
 * docenas = componente huevos, ambos ya ponderados/estampados en el WeeklyBasketItem.
 *
 * SOLO LECTURA. Pensado para la reconciliación contra los listados de administración
 * (docs/comparar_reparto.py) SIN pasar por la web ni por scraping con cookies: se corre
 * con override de DATABASE_URL contra un clon limpio de golden, sin tocar .env.local ni
 * bloquear las sesiones abiertas:
 *
 *   bin/db-play-reset && \
 *   ddev exec "DATABASE_URL='mysql://db:db@db:3306/db_play?serverVersion=8.0' \
 *              php bin/console app:dump-delivery-listado \
 *                --out=docs/socios-import/reparto_detalle_web.csv"
 *
 * El CSV reproduce las columnas que consume comparar_reparto.py (delivery_date,
 * partner_id, cesta_factor, dozens); el resto son para lectura humana.
 */
#[AsCommand(
    name: 'app:dump-delivery-listado',
    description: 'Vuelca el listado de reparto de un nodo (persona × viernes) a CSV. Solo lectura.'
)]
class DumpDeliveryListadoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly WeeklyBasketItemRepository $weeklyBasketItemRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Id del nodo a volcar', '1')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Fecha desde (Y-m-d) del rango de Baskets', '2026-04-01')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Fecha hasta (Y-m-d) del rango de Baskets', '2026-06-30')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Fichero CSV de salida (vacío = stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $node = $this->em->getRepository(Node::class)->find((int) $input->getOption('node'));
        if ($node === null) {
            $io->error(sprintf('No existe el nodo %s.', $input->getOption('node')));
            return Command::FAILURE;
        }

        $baskets = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b WHERE b.date BETWEEN :from AND :to ORDER BY b.date ASC'
        )
            ->setParameter('from', $input->getOption('from'))
            ->setParameter('to', $input->getOption('to'))
            ->getResult();

        $rows = [];
        foreach ($baskets as $basket) {
            $weeklyBaskets = $this->weeklyBasketRepo->findForNodeAndBasket($node, $basket);
            $amounts = $this->weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);

            foreach ($weeklyBaskets as $wb) {
                $partner = $wb->getPartner();
                $wbAmounts = $amounts[$wb->getId()] ?? [];
                $deliveryDate = $wb->getDeliveryDate() ?? $basket->getDate();
                $dozens = (float) ($wbAmounts[BasketComponent::ID_EGGS] ?? 0.0);

                $rows[] = [
                    'delivery_date' => $deliveryDate->format('Y-m-d'),
                    'basket_id'     => $basket->getId(),
                    'partner_id'    => $partner?->getId() ?? '',
                    'nombre'        => $partner?->getNameForDelivery() ?? '',
                    // Flag que consume comparar_reparto.py: cuenta las docenas solo si '1'.
                    'eggs_today'    => $dozens > 0 ? '1' : '0',
                    'cesta_factor'  => $this->num($wbAmounts[BasketComponent::ID_VEGETABLES] ?? 0.0),
                    'dozens'        => $this->num($dozens),
                ];
            }
        }

        $this->writeCsv($input->getOption('out'), $rows);

        $io->success(sprintf(
            'Nodo %d · %d Baskets en [%s..%s] · %d filas volcadas%s',
            $node->getId(), count($baskets),
            $input->getOption('from'), $input->getOption('to'), count($rows),
            $input->getOption('out') ? ' → ' . $input->getOption('out') : '',
        ));

        return Command::SUCCESS;
    }

    /** Formato numérico estable: entero si lo es, si no decimal sin ruido. */
    private function num(float $n): string
    {
        return rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
    }

    /**
     * @param string|null $out
     * @param list<array<string,mixed>> $rows
     */
    private function writeCsv(?string $out, array $rows): void
    {
        $cols = ['delivery_date', 'basket_id', 'partner_id', 'nombre', 'eggs_today', 'cesta_factor', 'dozens'];
        $fh = $out ? fopen($out, 'w') : fopen('php://stdout', 'w');
        fputcsv($fh, $cols);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn (string $c) => $r[$c], $cols));
        }
        if ($out) {
            fclose($fh);
        }
    }
}
