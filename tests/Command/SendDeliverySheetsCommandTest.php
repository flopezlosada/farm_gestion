<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests del comando que manda el listado de reparto. Cubre los dos modos de fallo
 * peligrosos —que enviara estando apagado, y que fallara en rojo cada mañana por
 * no tener destinatario— y deja la selección de repartos al unit de
 * {@see \App\Service\Delivery\DeliverySheetSchedule}, que es donde vive.
 *
 * No muta configuración: la tarea y su email vienen apagados de fábrica, que es
 * justo el estado que aquí se comprueba.
 */
class SendDeliverySheetsCommandTest extends KernelTestCase
{
    /**
     * De fábrica la tarea está apagada, así que no envía nada y lo dice. Sale en
     * verde a propósito: una tarea inhibida no es una avería, y devolver error
     * haría que el reloj externo avisara de un problema inexistente.
     */
    public function testNoEnviaConLaTareaApagada(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivad', $tester->getDisplay());
    }

    /**
     * La previsualización funciona antes de configurar nada: no pasa por el gate
     * (para poder ver qué haría con la tarea apagada) y no exige destinatario,
     * porque no envía. Es lo primero que se hace al montar esto en producción, y
     * además ejercita de punta a punta la selección de repartos contra la BBDD.
     */
    public function testLaPrevisualizacionCorreConLaTareaApagada(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * Una fecha imposible no puede colarse: `new \DateTimeImmutable('2026-02-30')`
     * haría rollover silencioso a marzo y mandaría el listado del día equivocado.
     */
    public function testRechazaUnaFechaImposible(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute(['--dry-run' => true, '--date' => '2026-02-30']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Fecha inválida', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-delivery-sheets'));
    }
}
