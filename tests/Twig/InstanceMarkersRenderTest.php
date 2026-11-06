<?php

namespace App\Tests\Twig;

use App\Environment\AppInstance;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Prueba de RENDER de las marcas de instalación (franja, icono, color de la
 * barra del móvil y fondo). La instalación se pasa en el contexto, que manda
 * sobre la variable global: así el test no depende de cómo esté configurado
 * `APP_INSTANCE` donde se ejecute (en DDEV vale `local`, en CI está vacía).
 */
class InstanceMarkersRenderTest extends KernelTestCase
{
    /**
     * En producción la franja no existe.
     */
    public function testEnProduccionNoHayFranja(): void
    {
        $html = $this->render('_components/instance_banner.html.twig', AppInstance::Production);

        $this->assertSame('', trim($html));
    }

    /**
     * En staging la franja dice que nada es real.
     */
    public function testEnStagingLaFranjaAvisaDeQueNadaEsReal(): void
    {
        $banner = (new Crawler($this->render('_components/instance_banner.html.twig', AppInstance::Staging)))
            ->filter('.app-instance-banner');

        $this->assertCount(1, $banner);
        $this->assertStringContainsString('nada de lo que hagas aquí es real', $banner->text());
    }

    /**
     * En producción el <head> deja lo de siempre: el favicon.ico, el color que
     * pide el layout y ningún estilo que tiña el fondo.
     */
    public function testEnProduccionElHeadConservaLoDeSiempre(): void
    {
        $head = $this->head(AppInstance::Production, ['theme_color' => '#4a7c3a']);

        $this->assertStringEndsWith('favicon.ico', (string) $head->filter('link[rel="icon"]')->attr('href'));
        $this->assertSame('#4a7c3a', $head->filter('meta[name="theme-color"]')->attr('content'));
        $this->assertCount(0, $head->filter('style'));
    }

    /**
     * Un layout sin color propio sigue sin meta theme-color en producción.
     */
    public function testEnProduccionSinColorPropioNoHayThemeColor(): void
    {
        $this->assertCount(0, $this->head(AppInstance::Production)->filter('meta[name="theme-color"]'));
    }

    /**
     * En staging el color del layout cede al de pruebas, el icono cambia y el
     * fondo se tiñe.
     */
    public function testEnStagingCambianIconoColorYFondo(): void
    {
        $head = $this->head(AppInstance::Staging, ['theme_color' => '#4a7c3a']);

        $this->assertStringEndsWith('favicon-staging.svg', (string) $head->filter('link[rel="icon"]')->attr('href'));
        $this->assertSame('#e8590c', $head->filter('meta[name="theme-color"]')->attr('content'));
        $this->assertStringContainsString('--csa-bg: '.AppInstance::Staging->backgroundTint(), $head->filter('style')->text());
    }

    /**
     * Renderiza el <head> parcial dentro de un documento para poder filtrarlo.
     *
     * @param array<string, mixed> $context
     */
    private function head(AppInstance $instance, array $context = []): Crawler
    {
        $html = $this->render('_components/instance_head.html.twig', $instance, $context);

        return (new Crawler('<html><head>'.$html.'</head><body></body></html>'))->filter('head');
    }

    /**
     * Renderiza una plantilla con la instalación indicada.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $template, AppInstance $instance, array $context = []): string
    {
        self::bootKernel();

        return self::getContainer()->get(Environment::class)
            ->render($template, ['app_instance' => $instance] + $context);
    }
}
