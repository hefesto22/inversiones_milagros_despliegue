<?php

namespace App\Exceptions;

use Exception;

/**
 * El cliente ya tiene una venta de ruta viva hoy y no hay autorización que
 * habilite una segunda.
 *
 * Se lanza dentro de la transacción de la venta para abortarla. El mensaje va
 * derecho a la pantalla del chofer, así que se escribe en español humano y
 * dice qué hacer, no solo qué pasó.
 *
 * @see \App\Services\Viaje\LimiteVentaDiariaService
 */
class LimiteVentaDiariaException extends Exception
{
    public function __construct(string $message = 'Este cliente ya tiene una factura de ruta el día de hoy.')
    {
        parent::__construct($message);
    }
}
