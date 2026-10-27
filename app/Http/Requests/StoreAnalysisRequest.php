<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnalysisRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'regex:/^https?:\/\//i'],
            'authorization_attestation' => ['required', 'string', 'min:20', 'max:500'],
            'user_context' => ['sometimes', 'array', 'max:10'],
            'user_context.*' => ['required', 'string', 'min:1', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
