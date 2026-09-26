<?php

namespace App\Reporting;

use App\Contracts\ExternalExceptionReporter;
use Throwable;

class NullExternalExceptionReporter implements ExternalExceptionReporter
{
    public function report(Throwable $exception, array $context): void
    {
        // External exception reporting is disabled.
    }
}
