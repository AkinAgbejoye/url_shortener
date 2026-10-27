<?php

namespace App\Exceptions;

use RuntimeException;

class AcquisitionFailure extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('The page could not be acquired within the configured limits.');
    }
}
