<?php

namespace App\Data;

final readonly class RemotePageResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $peerIp,
        public float $elapsedMilliseconds,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }
}
