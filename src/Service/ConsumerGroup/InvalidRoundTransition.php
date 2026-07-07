<?php

namespace App\Service\ConsumerGroup;

/**
 * Se lanza cuando se intenta una transición de estado no permitida en una ronda
 * del grupo de consumo (p. ej. confirmar una ronda ya entregada). Los controllers
 * la capturan para devolver un flash de error en vez de un 500.
 */
class InvalidRoundTransition extends \DomainException
{
}
