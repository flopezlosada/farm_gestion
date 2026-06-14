<?php

namespace App\Service\Delivery\Invariant;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Una LEY del dominio de reparto: condición que el estado materializado
 * (WeeklyBasket y sus satélites) debe cumplir SIEMPRE, para todos los socios
 * a la vez. Las implementaciones son de SOLO LECTURA — pueden correr contra
 * cualquier BBDD, la golden incluida.
 *
 * Cada ley se evalúa de la fecha `$from` (normalmente hoy) en adelante: el
 * pasado es historia ya reconciliada y validarlo contra la configuración
 * actual daría falsos positivos. Las leyes que necesitan mirar atrás
 * (p. ej. la conservación de shifts recientes) gestionan su propia ventana.
 *
 * Diseño y catálogo completo de leyes: memoria delivery-invariants-design.
 * Las recoge {@see \App\Command\VerifyDeliveryInvariantsCommand} vía el tag.
 */
#[AutoconfigureTag('app.delivery_invariant')]
interface DeliveryInvariant
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /**
     * Código corto y estable de la ley (p. ej. "L1"), el mismo que usa el
     * documento de diseño. Sirve para filtrar con --law y para referirse a
     * ella en informes.
     *
     * @return string
     */
    public function code(): string;

    /**
     * Nombre legible de la ley, en una línea.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Gravedad de una violación: ERROR rompe el comando (exit != 0),
     * WARNING se reporta pero no tumba la verificación.
     *
     * @return string Una de las constantes SEVERITY_*.
     */
    public function severity(): string;

    /**
     * Evalúa la ley sobre el estado actual y devuelve una violación por
     * línea, ya formateada para humanos (socio, semana y detalle).
     *
     * @param \DateTimeImmutable $from Inicio de la ventana de validación (hoy).
     * @return list<string> Vacío si la ley se cumple.
     */
    public function check(\DateTimeImmutable $from): array;
}
