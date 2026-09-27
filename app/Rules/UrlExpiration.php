<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UrlExpiration implements ValidationRule
{
    public function __construct(private readonly int $maxLifetimeDays) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isIso8601($value)) {
            $fail('The expiration must be a valid ISO-8601 timestamp with a timezone.');

            return;
        }

        $expiration = CarbonImmutable::parse($value)->utc();
        $now = CarbonImmutable::now('UTC');

        if ($expiration->lessThanOrEqualTo($now)) {
            $fail('The expiration must be in the future.');

            return;
        }

        if ($expiration->greaterThan($now->addDays($this->maxLifetimeDays))) {
            $fail("The expiration may not be more than {$this->maxLifetimeDays} days in the future.");
        }
    }

    private function isIso8601(string $value): bool
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
            $value,
        ) !== 1) {
            return false;
        }

        $parts = date_parse($value);

        return $parts['error_count'] === 0 && $parts['warning_count'] === 0;
    }
}
