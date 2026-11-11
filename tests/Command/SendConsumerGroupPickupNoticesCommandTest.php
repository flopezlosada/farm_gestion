<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El comando del aviso de recogida del grupo de consumo está declarado en el
 * manifiesto de tareas y arranca con sus servicios reales. Lo que decide a quién
 * se avisa lo cubre {@see \App\Tests\Service\ConsumerGroup\ConsumerGroupPickupNotifierTest}.
 */
class SendConsumerGroupPickupNoticesCommandTest extends KernelTestCase
{
    /**
     * Un día sin reparto no manda nada y sale en verde.
     */
    public function testUnDiaSinRecogidasNoMandaNada(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--dry-run' => true, '--date' => '2099-07-03']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nadie recoge un pedido del grupo de consumo', $tester->getDisplay());
    }

    /**
     * Una fecha imposible se rechaza en vez de avisar del día equivocado.
     */
    public function testUnaFechaInvalidaFalla(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true, '--date' => '2026-02-30']));
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:send-consumer-group-pickup-notices'));
    }
}
