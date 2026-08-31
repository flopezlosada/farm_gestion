<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests del comando que confirma la cesta a lxs socixs. Aquí el modo de fallo
 * peligroso es más caro que en el del listado: éste escribe a toda la gente que
 * recoge, no a una dirección interna, así que lo primero que hay que asegurar es
 * que de fábrica NO envía. A quién se escribe y qué se le dice lo cubre el unit de
 * {@see \App\Service\Delivery\DeliveryConfirmationMailer}.
 */
class SendDeliveryConfirmationsCommandTest extends KernelTestCase
{
    /**
     * De fábrica la tarea está apagada: no escribe a nadie y lo dice. En verde,
     * porque una tarea inhibida no es una avería.
     */
    public function testNoEscribeANadieConLaTareaApagada(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivad', $tester->getDisplay());
    }

    /**
     * La previsualización funciona antes de configurar nada y sin enviar: es como
     * se comprueba a quién se escribiría antes de encender esto en producción.
     */
    public function testLaPrevisualizacionCorreConLaTareaApagada(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-delivery-confirmations'));
    }
}
