<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke del verificador de invariantes: el comando arranca, evalúa las leyes
 * contra db_test (fixtures) y termina con SUCCESS o FAILURE — nunca con un
 * crash. No fija el resultado porque las fixtures sintéticas pueden violar
 * leyes legítimamente; lo que protege es que todas las leyes EJECUTAN (sus
 * DQL son válidas contra el esquema real) y que el filtro --law funciona.
 */
class VerifyDeliveryInvariantsCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:verify-delivery-invariants'));
    }

    public function testEvaluaTodasLasLeyesSinReventar(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertContains($exitCode, [0, 1], 'El comando debe terminar en SUCCESS o FAILURE, no crashear.');
        $display = $tester->getDisplay();
        foreach (['L1', 'L5', 'L5F', 'L6', 'L8', 'L9', 'L10', 'L11', 'L12', 'L15', 'L16', 'L17', 'L18', 'L19', 'L20', 'L21', 'L22', 'L23', 'L25', 'L26'] as $code) {
            $this->assertStringContainsString($code, $display, sprintf('La ley %s debe aparecer en el informe.', $code));
        }
    }

    public function testFiltroPorLey(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--law' => ['L1']]);

        $this->assertContains($exitCode, [0, 1]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('L1', $display);
        $this->assertStringNotContainsString('L26', $display, 'Con --law L1 no deben evaluarse otras leyes.');
    }

    public function testFiltroSinCoincidencias(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--law' => ['L999']]);

        $this->assertSame(2, $exitCode, 'Un filtro sin coincidencias debe terminar en INVALID.');
    }

    /**
     * La opción --max (0 = sin tope de violaciones impresas) no rompe el comando.
     */
    public function testMaxSinTopeNoRevienta(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--max' => '0']);

        $this->assertContains($exitCode, [0, 1]);
    }
}
