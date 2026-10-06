<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Añade headers de seguridad básicos a todas las respuestas HTTP.
 *
 *   X-Frame-Options: DENY
 *     - Impide que cualquier dominio embeba el sitio en un iframe.
 *       Defensa contra clickjacking (engañar al usuario para que
 *       haga clic en algo dentro de un iframe disfrazado). Salvo que
 *       la respuesta traiga ya la suya (SAMEORIGIN para enseñar un
 *       documento propio dentro de una página propia).
 *
 *   X-Content-Type-Options: nosniff
 *     - Le dice al navegador que respete el Content-Type declarado y
 *       no intente "adivinar" otro (MIME sniffing), porque un fichero
 *       subido podría ejecutarse como script si el sniffing lo
 *       reclasificara.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *     - Limita la información que se envía en el header Referer al
 *       navegar a sitios externos. Los browsers modernos ya lo aplican
 *       por defecto, pero declararlo explícitamente cierra el caso para
 *       cualquier browser/proxy intermedio que no haya adoptado el
 *       default.
 *
 * HSTS y Content-Security-Policy quedan fuera a propósito:
 *  - HSTS exige tener HTTPS estable y no querer degradar nunca. Va en
 *    Fase 11 (despliegue) cuando esté confirmado.
 *  - CSP exige auditar inline scripts, CKEditor, TinyMCE, etc. Se
 *    aborda como tarea propia, no como header de un solo trazo.
 */
class SecurityHeadersListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        // Una respuesta que declara su propia política de marcos la conserva: el
        // documento de una factura se enseña dentro de la página de revisión, en
        // un marco del MISMO sitio, y con DENY el navegador lo dejaría en blanco.
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
