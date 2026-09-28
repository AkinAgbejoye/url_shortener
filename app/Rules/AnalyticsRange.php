<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AnalyticsRange implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,3}d$/', $value) !== 1) {
            $fail('The analytics range must use a positive number of days, such as 7d.');

            return;
        }

        $maximum = self::maximumDays();

        if (self::days($value) > $maximum) {
            $fail("The analytics range may not exceed {$maximum} days.");
        }
    }

    public static function days(string $range): int
    {
        return (int) substr($range, 0, -1);
    }

    public static function maximumDays(): int
    {
        return max(1, (int) config('url_shortener.analytics.max_query_days', 90));
    }
}
