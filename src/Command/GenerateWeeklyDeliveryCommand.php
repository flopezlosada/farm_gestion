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
 * CONGELA (materializa) el listado de la semana que ENTRA EN OPERACIÓN: por
 * defecto solo el viernes más próximo (1 semana). Pensado para correr por cron
 * los lunes 06:00, de modo que cada lunes blinda la semana de esa misma semana
 * y solo esa, para que no se mueva bajo quien reparte.
 *
 * Es la pieza "congelado al entrar en operación" del rework de MATERIALIZACIÓN
 * TARDÍA: las semanas futuras NO se materializan por adelantado (antes se hacían
 * 4); se dibujan al vuelo desde las reglas ({@see WeeklyBasketGenerator::projectLinesForNode}),
 * de forma que un festivo o un cambio metido después siga surtiendo efecto. Las
 * acciones autoservicio del panel sobre semanas futuras NO necesitan WeeklyBasket
 * pre-materializados: se guardan como intents (PartnerDeliveryShift) y la
 * proyección los aplica; al congelar la semana, el generador los materializa.
 *
 * --weeks sigue disponible para forzar más semanas (p. ej. un backfill puntual).
 * Como WeeklyBasketGenerator es idempotente para Baskets ya generados (rama
 * reuseExistingWeeklyBaskets), correrlo a diario o varias veces no rompe nada.
 *
 * Las vistas de reparto (byNode v2 y su PDF) ya NO congelan al ver: el
 * {@see \App\Service\Delivery\DeliveryModeResolver} decide por nodo y, en una
 * semana futura sin piedra, DIBUJA desde la proyección sin persistir. La
 * materialización es responsabilidad exclusiva de este cron (y del rematerializador
 * a mano). Así que congelar es un acto deliberado del lunes, no un efecto colateral
 * de abrir una pantalla.
 */
#[AsCommand(name: 'app:generate-weekly-delivery', description: 'Congela el listado de la semana que entra en operación (cron lunes).')]
class GenerateWeeklyDeliveryCommand extends Command
{
    /** Por defecto solo la semana que entra en operación (materialización tardía). */
    private const DEFAULT_WEEKS = 1;

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
            ->addOption('weeks', null, InputOption::VALUE_REQUIRED, 'Cuántos viernes congelar desde hoy (default 1: solo el que entra en operación)', self::DEFAULT_WEEKS)
            ->addOption('basket-id', null, InputOption::VALUE_REQUIRED, 'Procesar un Basket concreto (ignora --weeks). Útil para regenerar ciclos pasados tras una corrección.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los Baskets que tocaría sin persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $weeks = max(1, (int) $input->getOption('weeks'));
        $dryRun = (bool) $input->getOption('dry-run');
        $basketId = $input->getOption('basket-id');

        if ($basketId !== null) {
            $basket = $this->em->getRepository(Basket::class)->find((int) $basketId);
            if ($basket === null) {
                $io->error(sprintf('No existe Basket con id=%d.', (int) $basketId));
                return Command::FAILURE;
            }
            $baskets = [$basket];
        } else {
            $baskets = $this->upcomingBaskets($weeks);
        }

        if (empty($baskets)) {
            $io->warning('No hay Baskets que procesar. Nada que hacer.');
            return Command::SUCCESS;
        }

        $rows = [];
        $generatedCount = 0;

        foreach ($baskets as $basket) {
            $existing = $this->em->getRepository(WeeklyBasket::class)
                ->findOneBy(['basket' => $basket]);
            $alreadyListed = $existing !== null;

            $exception = $this->exceptionRepository->findGlobalForBasket($basket);
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
