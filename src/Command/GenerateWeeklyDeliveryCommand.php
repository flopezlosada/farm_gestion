<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\WeeklyBasket;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Materializa el listado de los próximos N viernes (default 4) usando
 * WeeklyBasketGenerator. Pensado para correr por cron los lunes 06:00,
 * de forma que las acciones autoservicio del panel (saltar cesta,
 * cambiar nodo, cambio puntual de viernes) tengan los WeeklyBasket
 * disponibles sin necesidad de que admin haya entrado a la pantalla
 * de reparto.
 *
 * Como WeeklyBasketGenerator es idempotente para Baskets ya generados
 * (entra en la rama reuseExistingWeeklyBaskets), correrlo a diario o
 * varias veces no rompe nada — solo añade listados nuevos cuando hay
 * Baskets que aún no se procesaron.
 */
#[AsCommand(name: 'app:generate-weekly-delivery', description: 'Materializa el listado de los próximos N viernes (cron lunes).')]
class GenerateWeeklyDeliveryCommand extends Command
{
    private const DEFAULT_WEEKS = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
        private readonly DeliveryExceptionRepository $exceptionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('weeks', null, InputOption::VALUE_REQUIRED, 'Cuántos viernes adelante materializar', self::DEFAULT_WEEKS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los Baskets que tocaría sin persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $weeks = max(1, (int) $input->getOption('weeks'));
        $dryRun = (bool) $input->getOption('dry-run');

        $baskets = $this->upcomingBaskets($weeks);
        if (empty($baskets)) {
            $io->warning('No hay Baskets futuros registrados. Nada que hacer.');
            return Command::SUCCESS;
        }

        $rows = [];
        $generatedCount = 0;

        foreach ($baskets as $basket) {
            $existing = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket]);
            $alreadyListed = $existing !== null;

            $exception = $this->exceptionRepository->findByFriday($basket->getDate());
            $cancelled = $exception !== null && $exception->isCancelled();

            $status = $cancelled ? 'cancelado (sin reparto)'
                : ($alreadyListed ? 'ya listado' : ($dryRun ? 'se generaría' : 'generando'));

            if (!$dryRun && !$cancelled && !$alreadyListed) {
                $report = $this->generator->generateForBasket($basket);
                $created = $report->basket_weekly_partners_amount
                    + $report->basket_biweekly_partners_amount
                    + $report->basket_monthly_partners_amount
                    + $report->basket_old_half_basket_partners_amount
                    + $report->basket_only_egg_partners_amount;
                $generatedCount += $created;
                $status = sprintf('listado generado (%d cestas)', $created);
            }

            $rows[] = [
                $basket->getId(),
                $basket->getDate()->format('Y-m-d'),
                $status,
            ];
        }

        $io->table(['Basket', 'Viernes', 'Estado'], $rows);

        if ($dryRun) {
            $io->success('Dry-run: sin cambios en BBDD.');
        } else {
            $io->success(sprintf('Procesados %d Baskets. Cestas materializadas en esta ejecución: %d.', count($baskets), $generatedCount));
        }

        return Command::SUCCESS;
    }

    /**
     * Los próximos N Basket ordenados por fecha ascendente, desde hoy.
     *
     * @return Basket[]
     */
    private function upcomingBaskets(int $weeks): array
    {
        return $this->em->createQueryBuilder()
            ->select('b')
            ->from(Basket::class, 'b')
            ->where('b.date >= :today')
            ->orderBy('b.date', 'ASC')
            ->setMaxResults($weeks)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
