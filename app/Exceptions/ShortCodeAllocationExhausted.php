<?php

namespace App\Exceptions;

use RuntimeException;

class ShortCodeAllocationExhausted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unable to allocate an available short code.');
    }
}
