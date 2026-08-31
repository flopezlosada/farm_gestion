<?php

namespace App\Tests\Mailer;

use App\Entity\Partner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Prueba de RENDER de la plantilla del recordatorio de recogida. Es la defensa
 * directa contra el fallo que confundió a Madrid en producción: el email decía
 * "este viernes ... en la CSA" (Torremocha) a socixs que recogen el MIÉRCOLES en
 * Madrid. Renderiza la plantilla con contextos reales y comprueba que el texto
 * refleja el día y el nodo correctos de cada socix, y que un reparto desplazado
 * comunica su fecha real (jueves), no el viernes.
 */
class PickupReminderEmailRenderTest extends KernelTestCase
{
    private const TEMPLATE = 'email/pickup_reminder.txt.twig';

    public function testMadridRecogeElMiercolesEnSuNodoNoElViernesEnTorremocha(): void
    {
        // 'next wednesday' garantiza un miércoles sin depender del calendario.
        $wednesday = (new \DateTimeImmutable('2099-01-01'))->modify('next wednesday');

        $render = $this->render([
            'pickup_date' => $wednesday,
            'node_name' => 'Cascorro',
            'was_shifted' => false,
        ]);

        $this->assertStringContainsString('miércoles', $render, 'A Madrid le debe decir miércoles.');
        $this->assertStringContainsString('Cascorro', $render, 'Debe nombrar su nodo, no la granja.');
        $this->assertStringNotContainsString('viernes', $render, 'NO debe decir viernes a quien recoge el miércoles.');
        $this->assertStringNotContainsString('Torremocha', $render);
    }

    public function testSierraRecogeElViernesEnTorremocha(): void
    {
        $friday = (new \DateTimeImmutable('2099-01-01'))->modify('next friday');

        $render = $this->render([
            'pickup_date' => $friday,
            'node_name' => 'Torremocha',
            'was_shifted' => false,
        ]);

        $this->assertStringContainsString('viernes', $render);
        $this->assertStringContainsString('Torremocha', $render);
        $this->assertStringNotContainsString('desplazado', $render, 'Un viernes normal no está desplazado.');
    }

    public function testRepartoDesplazadoComunicaElJuevesConAviso(): void
    {
        $thursday = (new \DateTimeImmutable('2099-01-01'))->modify('next thursday');

        $render = $this->render([
            'pickup_date' => $thursday,
            'node_name' => 'Torremocha',
            'was_shifted' => true,
        ]);

        $this->assertStringContainsString('jueves', $render, 'Debe comunicar el jueves real, no el viernes.');
        $this->assertStringNotContainsString('viernes', $render);
        $this->assertStringContainsString('desplazado', $render, 'Debe avisar de que el reparto se ha desplazado.');
    }

    /**
     * @param array<string, mixed> $overrides Contexto específico del caso.
     */
    private function render(array $overrides): string
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->render(self::TEMPLATE, array_merge([
            'partner' => (new Partner())->setName('Nacho'),
            'modality' => 'quincenal',
            'can_act' => false,
            'calendar_url' => 'https://csavegadejarama.org/panel/calendario',
            'deadline' => (new \DateTimeImmutable('2099-01-01'))->modify('next monday'),
        ], $overrides));
    }
}
