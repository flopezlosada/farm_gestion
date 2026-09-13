<?php

namespace App\Tests\Command;

use App\Command\ImportBudgetCommand;
use App\Entity\BudgetCategoryGroup;
use App\Repository\BudgetCategoryRepository;
use App\Repository\BudgetRepository;
use App\Service\Accounting\LedgerCategoryMap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit test del importador de presupuesto. El EntityManager y los repositorios se
 * simulan; sólo se ejerce la simulación (sin --force) porque es la que recorre
 * readAndMap()/signFor() sin tocar BBDD.
 */
class ImportBudgetCommandTest extends TestCase
{
    /**
     * Financiación es la única partida donde el bloque del Excel no basta para saber
     * el signo: «Préstamos recibidos» y «Cuotas de préstamo» comparten bloque
     * FINANCIACION, así que el signo tiene que venir del propio importe leído.
     * `signFor()` tenía un `default => -1` que forzaba negativo también a los
     * préstamos recibidos (ingreso).
     */
    public function testFinanciacionRespetaElSignoDelFichero(): void
    {
        $csv = $this->csvFile([
            ['FINANCIACION', 'Préstamos socios', '1', '5000'],
            ['FINANCIACION', 'Cuotas préstamo', '1', '-200'],
        ]);

        $tester = $this->run($csv);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('5.000,00', $display);
        $this->assertStringContainsString('-200,00', $display);
    }

    /**
     * Ingresos y gastos sí distinguen por bloque: el importador fuerza el signo con
     * independencia de cómo venga tecleado el importe en el Excel.
     */
    public function testIngresosYGastosFuerzanElSignoPorBloque(): void
    {
        $csv = $this->csvFile([
            ['INGRESOS', 'Cuota', '1', '100'],
            ['GASTOS', 'Semillas y plantel', '1', '50'],
        ]);

        $tester = $this->run($csv);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('100,00', $display);
        $this->assertStringContainsString('-50,00', $display);
    }

    /** @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows */
    private function csvFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ppto');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['bloque', 'linea', 'mes', 'importe']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function run(string $csvPath): CommandTester
    {
        $groups = [
            'INGRESOS' => $this->group(1, 'INGRESOS', BudgetCategoryGroup::KIND_INCOME),
            'HUERTA' => $this->group(2, 'HUERTA', BudgetCategoryGroup::KIND_EXPENSE),
            'FINANCIACIÓN' => $this->group(3, 'FINANCIACIÓN', BudgetCategoryGroup::KIND_FINANCING),
        ];

        $groupRepo = $this->createMock(EntityRepository::class);
        $groupRepo->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => $groups[$criteria['name']] ?? null
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [BudgetCategoryGroup::class, $groupRepo],
        ]);

        $categories = $this->createMock(BudgetCategoryRepository::class);
        $budgets = $this->createMock(BudgetRepository::class);

        $command = new ImportBudgetCommand($em, new LedgerCategoryMap(), $categories, $budgets);
        $tester = new CommandTester($command);
        $tester->execute(['file' => $csvPath, '--year' => '2026']);

        unlink($csvPath);

        return $tester;
    }

    private function group(int $id, string $name, int $kind): BudgetCategoryGroup
    {
        $group = (new BudgetCategoryGroup())->setName($name)->setKind($kind);
        $property = new \ReflectionProperty($group, 'id');
        $property->setAccessible(true);
        $property->setValue($group, $id);

        return $group;
    }
}
