<?php

namespace App\Contracts;

interface IntelligenceDependencyProbe
{
    /**
     * @return array<string, array{status: 'ready'|'not_ready'|'disabled', duration_ms: float}>
     */
    public function check(): array;
}
