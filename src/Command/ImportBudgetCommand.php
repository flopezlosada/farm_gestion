<?php

namespace App\Command;

use App\Entity\Budget;
use App\Entity\BudgetCategoryGroup;
use App\Entity\BudgetLine;
use App\Repository\BudgetCategoryRepository;
use App\Repository\BudgetRepository;
use App\Service\Accounting\LedgerCategoryMap;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trae un presupuesto del Excel al que lleva la aplicación.
 *
 * Columnas esperadas, con cabecera: bloque, linea, mes, importe. El bloque es
 * INGRESOS, GASTOS, INVERSION o FINANCIACION, y hace falta porque «Formación» y
 * «Grupo de Consumo» aparecen en ingresos y en gastos con el mismo nombre.
 *
 * Los importes vienen del Excel SIN signo, en columnas separadas por bloque. Aquí
 * se guardan con el mismo criterio que los apuntes —negativo lo que sale— para que
 * comparar previsto y real sea restar y ya.
 *
 * Varias líneas del Excel pueden caer en la misma partida (los tres conceptos
 * salariales van a «Nóminas»): se suman.
 *
 * Por seguridad sólo informa; escribe con --force.
 */
#[AsCommand(
    name: 'app:import-budget',
    description: 'Importa un presupuesto anual desde un CSV.',
)]
class ImportBudgetCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LedgerCategoryMap $categoryMap,
        private readonly BudgetCategoryRepository $categories,
        private readonly BudgetRepository $budgets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', null, 'CSV con el presupuesto')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Año del presupuesto')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Cómo llamarlo', 'Aprobado en asamblea')
            ->addOption('approved', null, InputOption::VALUE_NONE, 'Marcarlo como el aprobado por la asamblea')
            ->addOption('opening-cash', null, InputOption::VALUE_REQUIRED, 'Saldo de tesorería con el que arranca el año', '0')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Escribe de verdad (sin esto sólo informa)')
            ->addOption('separator', null, InputOption::VALUE_REQUIRED, 'Separador de columnas', ',');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $year = (int) $input->getOption('year');
        $force = (bool) $input->getOption('force');

        if ($year < 2000) {
            $io->error('Hace falta --year.');

            return Command::FAILURE;
        }
        if (!is_readable($file)) {
            $io->error(sprintf('No puedo leer "%s".', $file));

            return Command::FAILURE;
        }

        [$totals, $problems] = $this->readAndMap($file, (string) $input->getOption('separator'));

        if ($problems !== []) {
            $io->error('Hay líneas que no sé a qué partida llevar. No se escribe nada.');
            $io->listing($problems);

            return Command::FAILURE;
        }

        $this->summarize($io, $totals);

        if (!$force) {
            $io->warning('Simulación. Repite con --force para escribir.');

            return Command::SUCCESS;
        }

        $name = (string) $input->getOption('name');
        if ($this->budgetExists($year, $name)) {
            $io->error(sprintf('Ya hay un presupuesto %d llamado "%s". Usa otro --name o bórralo antes.', $year, $name));

            return Command::FAILURE;
        }

        $budget = (new Budget())
            ->setYear($year)
            ->setName($name)
            ->setApproved((bool) $input->getOption('approved'))
            ->setOpeningCash(number_format((float) $input->getOption('opening-cash'), 2, '.', ''));
        if ($budget->isApproved()) {
            $budget->setApprovedAt(new \DateTimeImmutable(sprintf('%d-01-01', $year)));
        }
        $this->em->persist($budget);

        $lines = 0;
        foreach ($totals as $key => $months) {
            [$groupName, $categoryName] = explode('|', (string) $key, 2);
            $category = $this->categories->findOneByGroupAndName($groupName, $categoryName);
            if ($category === null) {
                $io->error(sprintf('La partida "%s › %s" no está en el catálogo.', $groupName, $categoryName));

                return Command::FAILURE;
            }
            foreach ($months as $month => $amount) {
                if (abs($amount) < 0.005) {
                    continue;
                }
                $budget->addLine(new BudgetLine($budget, $category, (int) $month, number_format($amount, 2, '.', '')));
                ++$lines;
            }
        }

        $this->em->flush();
        $io->success(sprintf('Presupuesto %d «%s» creado con %d líneas.', $year, $name, $lines));

        return Command::SUCCESS;
    }

    /** Si ya existe un presupuesto de ese año con ese nombre (la clave única). */
    private function budgetExists(int $year, string $name): bool
    {
        foreach ($this->budgets->findForYear($year) as $budget) {
            if ($budget->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lee el CSV y acumula por partida y mes, ya con el signo que le toca.
     *
     * @return array{0: array<string, array<int, float>>, 1: string[]}
     */
    private function readAndMap(string $file, string $separator): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [[], ['No se pudo abrir el fichero.']];
        }

        $totals = [];
        $problems = [];
        $first = true;
        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            if ($first) {
                $first = false;
                continue;
            }
            if (\count($data) < 4) {
                continue;
            }
            [$block, $label, $month, $raw] = $data;
            $amount = (float) str_replace(',', '.', (string) $raw);
            if (abs($amount) < 0.005) {
                continue;
            }

            $target = $this->categoryMap->resolveBudgetLine((string) $block, (string) $label);
            if ($target === null) {
                $key = $block.' › '.$label;
                if (!\in_array($key, $problems, true)) {
                    $problems[] = $key;
                }
                continue;
            }

            $sign = $this->signFor($target[0]);
            $key = $target[0].'|'.$target[1];
            $totals[$key][(int) $month] = ($totals[$key][(int) $month] ?? 0.0) + $sign * abs($amount);
        }
        fclose($handle);

        return [$totals, $problems];
    }

    /**
     * Signo que le corresponde a una partida por su grupo. El Excel trae los
     * importes sin signo porque los separa en bloques; aquí el signo es parte del
     * dato. La financiación es la excepción: un préstamo entra y sus cuotas salen,
     * así que se respeta el signo que traiga el fichero.
     */
    private function signFor(string $groupName): int
    {
        $group = $this->em->getRepository(BudgetCategoryGroup::class)->findOneBy(['name' => $groupName]);

        return match ($group?->getKind()) {
            BudgetCategoryGroup::KIND_INCOME => 1,
            BudgetCategoryGroup::KIND_EXPENSE, BudgetCategoryGroup::KIND_INVESTMENT => -1,
            default => -1,
        };
    }

    /**
     * Enseña lo que va a entrar: el total del año por partida.
     *
     * @param array<string, array<int, float>> $totals
     */
    private function summarize(SymfonyStyle $io, array $totals): void
    {
        ksort($totals);
        $rows = [];
        $sum = 0.0;
        foreach ($totals as $key => $months) {
            $total = array_sum($months);
            $sum += $total;
            $rows[] = [str_replace('|', ' › ', (string) $key), \count($months), number_format($total, 2, ',', '.')];
        }
        $rows[] = ['TOTAL (ingresos menos gastos)', '', number_format($sum, 2, ',', '.')];

        $io->table(['Partida', 'Meses', 'Año €'], $rows);
    }
}
