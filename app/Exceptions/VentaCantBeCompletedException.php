<?php

namespace App\Exceptions;

use Exception;

class VentaCantBeCompletedException extends Exception
{
    public function __construct(string $message = "La venta no puede ser completada en su estado actual")
    {
        parent::__construct($message);
    }
}
