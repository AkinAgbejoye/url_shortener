<?php

namespace App\Rules;

use App\Support\CustomAlias as AliasValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CustomAlias implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The custom alias must be a string.');

            return;
        }

        $alias = AliasValue::canonicalize($value);
        $minimum = max(1, (int) config('url_shortener.aliases.min_length', 3));
        $maximum = max($minimum, (int) config('url_shortener.aliases.max_length', 48));
        $length = mb_strlen($alias);

        if ($length < $minimum || $length > $maximum) {
            $fail("The custom alias must be between {$minimum} and {$maximum} characters.");

            return;
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $alias) !== 1) {
            $fail('The custom alias may contain letters, numbers, and single hyphens between groups.');

            return;
        }

        $reserved = array_map(
            static fn (mixed $reservedAlias): string => AliasValue::canonicalize((string) $reservedAlias),
            (array) config('url_shortener.aliases.reserved', []),
        );

        if (in_array($alias, $reserved, true)) {
            $fail('The custom alias is reserved and cannot be used.');
        }
    }
}
