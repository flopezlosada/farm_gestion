<?php

namespace App\Environment;

/**
 * Qué instalación de la aplicación está sirviendo la página: la de verdad o
 * una de pruebas.
 *
 * Existe porque a staging se le da acceso a gente de la asociación para que
 * pruebe, y staging es un calco de producción: mismos datos, mismas pantallas.
 * Sin una marca visible, quien tiene las dos abiertas no sabe en cuál está, y
 * acaba haciendo en la real lo que quería probar o dando por hecho en la de
 * pruebas lo que no ha pasado.
 *
 * No se deduce de `APP_ENV`: staging corre en `prod` a propósito, para
 * comportarse igual que producción. Esto es otra pregunta (dónde estoy, no
 * cómo me comporto) y se contesta con su propia variable, `APP_INSTANCE`.
 *
 * Producción es el valor VACÍO, y por eso es lo que sale si nadie configura
 * nada: el servidor de verdad no necesita tocar su `.env.local`. Un valor
 * desconocido (una errata en staging) no cae en silencio en producción:
 * el procesador `enum:` de Symfony lanza una excepción y la página no carga,
 * que es justo lo que interesa para que la errata no pase desapercibida.
 */
enum AppInstance: string
{
    case Production = '';
    case Staging = 'staging';
    case Local = 'local';

    /**
     * Si es la instalación real, la que no lleva ninguna marca.
     */
    public function isProduction(): bool
    {
        return self::Production === $this;
    }

    /**
     * Nombre corto que se enseña en la franja y en la pestaña del navegador.
     * Vacío en producción.
     */
    public function label(): string
    {
        return match ($this) {
            self::Production => '',
            self::Staging => 'PRUEBAS',
            self::Local => 'LOCAL',
        };
    }

    /**
     * Prefijo del `<title>`: lo que distingue dos pestañas abiertas a la vez,
     * que hoy se llaman exactamente igual. Vacío en producción.
     */
    public function titlePrefix(): string
    {
        return $this->isProduction() ? '' : '['.$this->label().'] ';
    }

    /**
     * Frase de la franja superior: qué consecuencias tiene lo que se haga aquí.
     * Vacía en producción.
     */
    public function notice(): string
    {
        return match ($this) {
            self::Production => '',
            self::Staging => 'Entorno de pruebas · nada de lo que hagas aquí es real',
            self::Local => 'Copia local de desarrollo',
        };
    }

    /**
     * Color de la barra del navegador en el móvil (`theme-color`), o `null`
     * para que cada layout conserve el suyo en producción.
     */
    public function themeColor(): ?string
    {
        return match ($this) {
            self::Production => null,
            self::Staging => '#e8590c',
            self::Local => '#5f6b7a',
        };
    }

    /**
     * Fondo general de las pantallas del sistema csa (`--csa-bg`), o `null`
     * para dejar el de siempre. Tono claro a propósito: tiñe el marco y deja
     * las tarjetas blancas, así lo que se prueba se ve como en producción.
     */
    public function backgroundTint(): ?string
    {
        return match ($this) {
            self::Production => null,
            self::Staging => '#fdeedd',
            self::Local => '#eceff3',
        };
    }

    /**
     * Ruta pública del icono de la pestaña, o `null` para usar el de siempre.
     */
    public function favicon(): ?string
    {
        return match ($this) {
            self::Production => null,
            self::Staging => 'favicon-staging.svg',
            self::Local => 'favicon-local.svg',
        };
    }
}
