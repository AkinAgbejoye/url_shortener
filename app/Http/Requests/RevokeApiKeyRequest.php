<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeApiKeyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.current_password' => 'Enter your current password to manage API keys.',
        ];
    }
}
