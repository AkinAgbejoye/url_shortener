<?php

namespace App\Exceptions;

use RuntimeException;

class AcquisitionPolicyViolation extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('The destination is not eligible for acquisition.');
    }
}
