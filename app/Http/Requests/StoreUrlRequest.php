<?php

namespace App\Http\Requests;

use App\Rules\UrlExpiration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'long_url' => ['required', 'url:http,https', 'max:2048'],
            'expires_at' => [
                'nullable',
                new UrlExpiration(max(1, (int) config('url_shortener.max_lifetime_days'))),
            ],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'long_url.url' => 'The long URL must be a valid HTTP or HTTPS URL.',
            'idempotency_key.string' => 'The Idempotency-Key header must be a string.',
            'idempotency_key.max' => 'The Idempotency-Key header may not be greater than 255 characters.',
        ];
    }

    public function idempotencyKey(): ?string
    {
        $idempotencyKey = $this->validated('idempotency_key');

        return is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $expiresAt = $this->validated('expires_at');

        return is_string($expiresAt) ? CarbonImmutable::parse($expiresAt)->utc() : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }
}
