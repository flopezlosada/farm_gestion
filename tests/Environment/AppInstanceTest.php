<?php

namespace App\Tests\Environment;

use App\Environment\AppInstance;
use PHPUnit\Framework\TestCase;

/**
 * Fija qué marca lleva cada instalación. Lo importante es que producción sale
 * del valor VACÍO (lo que tiene un servidor que no configura nada) y no lleva
 * ninguna marca, y que una errata no se confunde en silencio con producción.
 */
class AppInstanceTest extends TestCase
{
    /**
     * Sin configurar (`APP_INSTANCE=`) es producción, y producción no marca nada.
     */
    public function testElValorVacioEsProduccionSinNingunaMarca(): void
    {
        $instance = AppInstance::from('');

        $this->assertSame(AppInstance::Production, $instance);
        $this->assertTrue($instance->isProduction());
        $this->assertSame('', $instance->label());
        $this->assertSame('', $instance->titlePrefix());
        $this->assertSame('', $instance->notice());
        $this->assertNull($instance->themeColor());
        $this->assertNull($instance->backgroundTint());
        $this->assertNull($instance->favicon());
    }

    /**
     * Staging lleva todas las marcas, y la pestaña empieza por [PRUEBAS].
     */
    public function testStagingLlevaTodasLasMarcas(): void
    {
        $instance = AppInstance::from('staging');

        $this->assertFalse($instance->isProduction());
        $this->assertSame('[PRUEBAS] ', $instance->titlePrefix());
        $this->assertStringContainsString('nada de lo que hagas aquí es real', $instance->notice());
        $this->assertSame('#e8590c', $instance->themeColor());
        $this->assertNotNull($instance->backgroundTint());
        $this->assertSame('favicon-staging.svg', $instance->favicon());
    }

    /**
     * Ninguna instalación que no sea producción se queda sin alguna marca:
     * cada método que devuelve null/vacío en producción tiene valor en las demás.
     */
    public function testTodaInstalacionDePruebasTieneTodasLasMarcas(): void
    {
        $noProduction = array_filter(AppInstance::cases(), fn (AppInstance $i) => !$i->isProduction());

        $this->assertNotEmpty($noProduction);
        array_walk($noProduction, function (AppInstance $i): void {
            $this->assertNotSame('', $i->label(), $i->name);
            $this->assertNotSame('', $i->notice(), $i->name);
            $this->assertNotNull($i->themeColor(), $i->name);
            $this->assertNotNull($i->backgroundTint(), $i->name);
            $this->assertFileExists(dirname(__DIR__, 2).'/public/'.$i->favicon(), $i->name);
        });
    }

    /**
     * Una errata (mayúsculas, espacios) no es ninguna instalación: el
     * procesador `enum:` de Symfony usa `tryFrom` y lanza si da null, así que
     * la página no carga en vez de hacerse pasar por producción.
     */
    public function testUnaErrataNoEsNingunaInstalacion(): void
    {
        $this->assertNull(AppInstance::tryFrom('Staging'));
        $this->assertNull(AppInstance::tryFrom(' staging'));
        $this->assertNull(AppInstance::tryFrom('prod'));
    }
}
