<?php

namespace App\Support;

use App\Enums\UrlLifecycleState;

final readonly class UrlResolution
{
    private function __construct(
        public ?string $destination,
        public ?int $urlId,
        public string $outcome,
    ) {}

    public static function found(string $destination, int $urlId): self
    {
        return new self($destination, $urlId, 'found');
    }

    public static function missing(): self
    {
        return new self(null, null, 'not_found');
    }

    public static function unavailable(UrlLifecycleState $state): self
    {
        return new self(null, null, $state->value);
    }

    public function isFound(): bool
    {
        return $this->destination !== null;
    }
}
