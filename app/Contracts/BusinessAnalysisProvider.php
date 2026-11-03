<?php

namespace App\Contracts;

interface BusinessAnalysisProvider
{
    /**
     * Receives bounded sanitized observations only. Implementations must not be
     * given acquisition, filesystem, credentials, account, publishing, or tool access.
     *
     * @param  list<array{id: string, kind: string, statement: string}>  $observations
     * @param  list<string>  $userContext
     * @return list<array<string, mixed>>
     */
    public function analyze(array $observations, array $userContext): array;
}
