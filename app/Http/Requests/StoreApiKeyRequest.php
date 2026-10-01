<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $scopes = (array) config('url_shortener.api_keys.scopes', []);
        $maxNameLength = (int) config('url_shortener.api_keys.name_max_length', 80);
        $maxExpirationDays = (int) config('url_shortener.api_keys.max_expiration_days', 365);
        $latestExpiration = now()->addDays($maxExpirationDays)->toDateTimeString();

        return [
            'name' => ['required', 'string', 'min:1', "max:{$maxNameLength}"],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys($scopes))],
            'expires_at' => ['nullable', 'date', 'after:now', "before_or_equal:{$latestExpiration}"],
            'password' => ['required', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.current_password' => 'Enter your current password to manage API keys.',
            'scopes.*.in' => 'Choose a supported API-key scope.',
            'scopes.distinct' => 'Each API-key scope may be selected only once.',
        ];
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_values($this->validated('scopes'));
    }
}
