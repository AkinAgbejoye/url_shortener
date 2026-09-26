<?php

namespace App\Contracts;

use Throwable;

interface ExternalExceptionReporter
{
    /** @param array<string, string|null> $context */
    public function report(Throwable $exception, array $context): void;
}
