<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Support\OperationalMetrics;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiKeyAuthenticator
{
    private const DUMMY_HASH = '$2y$12$11z1Qrlh08EEjTQ7eG9Y7eNvE9DNBKtOS1.DRlh0s4zKj1D6H6TjW';

    public function __construct(private readonly OperationalMetrics $metrics) {}

    /** @return array{api_key: ApiKey|null, outcome: string} */
    public function authenticate(?string $authorization): array
    {
        if ($authorization === null || $authorization === '') {
            return $this->result(null, 'missing', false);
        }

        if (preg_match('/^Bearer (ak_[a-z0-9]{20})\.([A-Za-z0-9]{64})$/i', $authorization, $matches) !== 1) {
            $this->constantTimeCheck('', null);

            return $this->result(null, 'malformed');
        }

        $apiKey = ApiKey::query()
            ->with('user')
            ->where('public_id', $matches[1])
            ->first();
        $secretMatches = $this->constantTimeCheck($matches[2], $apiKey);

        if ($apiKey === null) {
            return $this->result(null, 'unknown');
        }

        if (! $secretMatches) {
            return $this->result(null, 'invalid');
        }

        if ($apiKey->revoked_at !== null) {
            return $this->result(null, 'revoked');
        }

        if ($apiKey->expires_at !== null && $apiKey->expires_at->isPast()) {
            return $this->result(null, 'expired');
        }

        $this->recordUsage($apiKey);

        return $this->result($apiKey, 'valid');
    }

    private function constantTimeCheck(string $secret, ?ApiKey $apiKey): bool
    {
        return Hash::check($secret, $apiKey?->secret_hash ?? self::DUMMY_HASH);
    }

    private function recordUsage(ApiKey $apiKey): void
    {
        try {
            $cutoff = now()->subSeconds(max(
                1,
                (int) config('url_shortener.api_keys.last_used_update_seconds', 300),
            ));

            ApiKey::query()
                ->whereKey($apiKey->getKey())
                ->where(function ($query) use ($cutoff): void {
                    $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $cutoff);
                })
                ->update(['last_used_at' => now()]);
        } catch (Throwable $exception) {
            try {
                Log::warning('api_key_last_used_update_failed', [
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Usage telemetry must never alter authentication.
            }
        }
    }

    /** @return array{api_key: ApiKey|null, outcome: string} */
    private function result(?ApiKey $apiKey, string $outcome, bool $log = true): array
    {
        try {
            $this->metrics->apiKeyAuthentication($outcome);
        } catch (Throwable) {
            // Authentication must not depend on metrics availability.
        }

        if ($log) {
            try {
                Log::info('api_key_authentication', ['outcome' => $outcome]);
            } catch (Throwable) {
                // Authentication must not depend on logging availability.
            }
        }

        return ['api_key' => $apiKey, 'outcome' => $outcome];
    }
}
