<?php

namespace App\Data;

final readonly class AcquisitionTarget
{
    /** @param list<string> $approvedIps */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public array $approvedIps,
        public string $connectIp,
    ) {}
}
