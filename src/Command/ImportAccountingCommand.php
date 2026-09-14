<?php

namespace App\Command;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Repository\BudgetCategoryRepository;
use App\Repository\FinancialAccountRepository;
use App\Service\Accounting\LedgerCategoryMap;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trae al libro los apuntes que hoy viven en el Excel de contabilidad.
 *
 * Come CSV y no XLSX a propósito: leer Excel en PHP obliga a meter PhpSpreadsheet
 * en el proyecto para usarlo una sola vez en la vida. El Excel se guarda como CSV
 * (o se convierte con el script del scratchpad) y aquí sólo hay lectura de texto.
 *
 * Columnas esperadas, con cabecera: cuenta, fecha, partida, concepto, proveedor,
 * factura, importe. El importe lleva signo: positivo entra, negativo sale.
 *
 * Por seguridad sólo informa; escribe con --force. Antes de guardar nada comprueba
 * que TODAS las etiquetas de partida se reconocen y que todas las cuentas existen:
 * si algo falla, lo lista y no escribe, porque media importación es peor que
 * ninguna.
 */
#[AsCommand(
    name: 'app:import-accounting',
    description: 'Importa apuntes del libro de contabilidad desde un CSV.',
)]
class ImportAccountingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LedgerCategoryMap $categoryMap,
        private readonly FinancialAccountRepository $accounts,
        private readonly BudgetCategoryRepository $categories,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', null, 'CSV con los apuntes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Escribe de verdad (sin esto sólo informa)')
            ->addOption('separator', null, InputOption::VALUE_REQUIRED, 'Separador de columnas', ',');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $force = (bool) $input->getOption('force');
        $separator = (string) $input->getOption('separator');

        if (!is_readable($file)) {
            $io->error(sprintf('No puedo leer "%s".', $file));

            return Command::FAILURE;
        }

        $rows = $this->readCsv($file, $separator);
        if ($rows === []) {
            $io->error('El fichero no tiene filas.');

            return Command::FAILURE;
        }
        $io->text(sprintf('Leídas %d filas de %s', \count($rows), basename($file)));

        [$prepared, $problems] = $this->prepare($rows, $io);

        if ($problems !== []) {
            $io->error('Hay filas que no se pueden imputar. No se escribe nada.');
            $io->listing(array_slice($problems, 0, 30));
            if (\count($problems) > 30) {
                $io->text(sprintf('… y %d más.', \count($problems) - 30));
            }

            return Command::FAILURE;
        }

        $this->summarize($io, $prepared);

        if (!$force) {
            $io->warning('Simulación. Repite con --force para escribir.');

            return Command::SUCCESS;
        }

        foreach ($prepared as $entry) {
            $this->em->persist($entry);
        }
        $this->em->flush();

        $io->success(sprintf('%d apuntes importados.', \count($prepared)));

        return Command::SUCCESS;
    }

    /**
     * Convierte cada fila en un apunte listo para guardar, o acumula el motivo por
     * el que no se puede. Nada se persiste aquí.
     *
     * @param array<int, array<string, string>> $rows
     *
     * @return array{0: AccountEntry[], 1: string[]}
     */
    private function prepare(array $rows, SymfonyStyle $io): array
    {
        $accountCache = [];
        $categoryCache = [];
        $prepared = [];
        $problems = [];

        foreach ($rows as $line => $row) {
            $accountName = trim($row['cuenta'] ?? '');
            $label = trim($row['partida'] ?? '');
            $amount = $this->parseAmount($row['importe'] ?? '');
            $date = $this->parseDate($row['fecha'] ?? '');

            if ($date === null) {
                $problems[] = sprintf('Línea %d: fecha ilegible "%s".', $line, $row['fecha'] ?? '');
                continue;
            }
            if ($amount === null || abs($amount) < 0.005) {
                $problems[] = sprintf('Línea %d: importe vacío o cero.', $line);
                continue;
            }

            $account = $accountCache[$accountName] ??= $this->findAccount($accountName);
            if ($account === null) {
                $problems[] = sprintf('Línea %d: no existe la cuenta "%s". Créala antes de importar.', $line, $accountName);
                continue;
            }

            $target = $this->categoryMap->resolve($label, $amount);
            if ($target === null) {
                $problems[] = sprintf('Línea %d: partida desconocida "%s".', $line, $label);
                continue;
            }

            $cacheKey = $target[0].'|'.$target[1];
            $category = $categoryCache[$cacheKey] ??= $this->categories->findOneByGroupAndName($target[0], $target[1]);
            if ($category === null) {
                $problems[] = sprintf('Línea %d: la partida "%s › %s" no está en el catálogo.', $line, $target[0], $target[1]);
                continue;
            }

            $prepared[] = (new AccountEntry())
                ->setDate($date)
                ->setAccount($account)
                ->setCategory($category)
                ->setConcept($this->truncate(trim($row['concepto'] ?? '') ?: 'Sin concepto', 255))
                ->setProviderName($this->truncate(trim($row['proveedor'] ?? ''), 150) ?: null)
                ->setInvoiceNumber($this->truncate(trim($row['factura'] ?? ''), 50) ?: null)
                ->setAmount(number_format($amount, 2, '.', ''));
        }

        return [$prepared, $problems];
    }

    /** Cuenta por nombre exacto, sin distinguir mayúsculas. */
    private function findAccount(string $name): ?FinancialAccount
    {
        foreach ($this->accounts->findAll() as $account) {
            if (mb_strtolower($account->getName()) === mb_strtolower($name)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Lee el CSV con la cabecera como claves, en minúsculas y sin acentos, para no
     * depender de cómo venga escrita.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $file, string $separator): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        $header = null;
        $rows = [];
        $line = 0;
        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            ++$line;
            if ($header === null) {
                $header = array_map(fn (string $h): string => $this->headerKey($h), $data);
                continue;
            }
            if ($data === [null] || $data === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = (string) ($data[$i] ?? '');
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** Normaliza un nombre de columna: minúsculas, sin acentos ni espacios. */
    private function headerKey(string $header): string
    {
        $clean = strtr(mb_strtolower(trim($header)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $clean);
    }

    /** Acepta 1234,56 y 1234.56, con o sin separador de miles. */
    private function parseAmount(string $raw): ?float
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }
        $clean = str_replace(' ', '', $clean);
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace('.', '', $clean);
        }
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** Acepta dd/mm/yyyy, yyyy-mm-dd y el número de serie de Excel. */
    private function parseDate(string $raw): ?\DateTimeInterface
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }

        if (ctype_digit($clean) && (int) $clean > 20000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+'.$clean.' days');
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format.'|', $clean);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    /** Recorta al largo de la columna, sin partir un carácter multibyte. */
    private function truncate(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    /**
     * Enseña lo que va a entrar antes de escribirlo: cuántos apuntes por cuenta y
     * qué suma cada partida. Es la última oportunidad de ver un disparate.
     *
     * @param AccountEntry[] $prepared
     */
    private function summarize(SymfonyStyle $io, array $prepared): void
    {
        $byAccount = [];
        $byCategory = [];
        foreach ($prepared as $entry) {
            $accountName = $entry->getAccount()?->getName() ?? '?';
            $byAccount[$accountName]['n'] = ($byAccount[$accountName]['n'] ?? 0) + 1;
            $byAccount[$accountName]['sum'] = ($byAccount[$accountName]['sum'] ?? 0) + (float) $entry->getAmount();

            $category = $entry->getCategory();
            $key = $category instanceof BudgetCategory ? $category->getQualifiedName() : '?';
            $byCategory[$key]['n'] = ($byCategory[$key]['n'] ?? 0) + 1;
            $byCategory[$key]['sum'] = ($byCategory[$key]['sum'] ?? 0) + (float) $entry->getAmount();
        }

        ksort($byAccount);
        ksort($byCategory);

        $io->section('Por cuenta');
        $io->table(['Cuenta', 'Apuntes', 'Neto €'], array_map(
            static fn (string $name, array $d): array => [$name, $d['n'], number_format($d['sum'], 2, ',', '.')],
            array_keys($byAccount),
            $byAccount
        ));

        $io->section('Por partida');
        $io->table(['Partida', 'Apuntes', 'Neto €'], array_map(
            static fn (string $name, array $d): array => [$name, $d['n'], number_format($d['sum'], 2, ',', '.')],
            array_keys($byCategory),
            $byCategory
        ));
    }
}
