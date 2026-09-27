<?php

namespace App\Http\Requests;

use App\Rules\UrlExpiration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUrlExpirationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'expires_at' => [
                'present',
                'nullable',
                new UrlExpiration(max(1, (int) config('url_shortener.max_lifetime_days'))),
            ],
        ];
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $expiresAt = $this->validated('expires_at');

        return is_string($expiresAt) ? CarbonImmutable::parse($expiresAt)->utc() : null;
    }

    public function managementToken(): ?string
    {
        $token = $this->header('X-Management-Token');

        return is_string($token) ? $token : null;
    }
}
