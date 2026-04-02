<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(string $message = "Stock insuficiente para completar esta operación")
    {
        parent::__construct($message);
    }
}
