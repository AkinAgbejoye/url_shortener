<?php

namespace App\Http\Requests;

use App\Rules\AnalyticsRange;
use Illuminate\Foundation\Http\FormRequest;

class ShowUrlAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', new AnalyticsRange],
        ];
    }

    public function rangeDays(): int
    {
        $range = $this->validated('range');

        return is_string($range)
            ? AnalyticsRange::days($range)
            : min(30, AnalyticsRange::maximumDays());
    }

    public function managementToken(): ?string
    {
        $token = $this->header('X-Management-Token');

        return is_string($token) ? $token : null;
    }
}
