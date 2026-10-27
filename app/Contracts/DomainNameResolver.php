<?php

namespace App\Contracts;

interface DomainNameResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
