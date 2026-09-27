<?php

namespace App\Exceptions;

use RuntimeException;

class CustomAliasConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The custom alias has already been taken.');
    }
}
