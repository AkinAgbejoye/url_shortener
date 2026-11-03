<?php

namespace App\Contracts;

interface DomainControlVerifier
{
    public function verify(string $domain, string $method, string $token): bool;
}
