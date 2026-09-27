<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UrlShortenerService
{
    public function __construct(private readonly Base62Service $base62) {}

    /** @return array{response: array<string, mixed>, created: bool, conflict: bool, management_token: string|null} */
    public function shorten(
        string $longUrl,
        ?string $idempotencyKey,
        ?CarbonImmutable $expiresAt = null,
    ): array {
        $requestHash = $this->requestHash($longUrl, $expiresAt);

        if ($idempotencyKey !== null) {
            $existing = IdempotencyKey::where('key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->replay($existing, $requestHash);
            }
        }

        try {
            return DB::transaction(function () use ($longUrl, $idempotencyKey, $requestHash, $expiresAt): array {
                $managementToken = bin2hex(random_bytes(32));
                $url = Url::create([
                    'long_url' => $longUrl,
                    'short_code' => 'pending-'.Str::uuid(),
                    'expires_at' => $expiresAt,
                    'management_token_hash' => hash('sha256', $managementToken),
                ]);
                $shortCode = $this->base62->encode($url->id);
                $url->update(['short_code' => $shortCode]);

                $response = [
                    'id' => $url->id,
                    'short_code' => $shortCode,
                    'short_url' => url('/'.$shortCode),
                    'long_url' => $url->long_url,
                    'expires_at' => $url->expires_at?->utc()->toIso8601String(),
                    'status' => $url->lifecycleState()->value,
                ];

                if ($idempotencyKey !== null) {
                    IdempotencyKey::create([
                        'key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'response' => $response,
                    ]);
                }

                return [
                    'response' => $response,
                    'created' => true,
                    'conflict' => false,
                    'management_token' => $managementToken,
                ];
            });
        } catch (QueryException $exception) {
            $existing = $idempotencyKey === null
                ? null
                : IdempotencyKey::where('key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }
    }

    /** @return array{response: array<string, mixed>, created: bool, conflict: bool, management_token: null} */
    private function replay(IdempotencyKey $existing, string $requestHash): array
    {
        return [
            'response' => $existing->response,
            'created' => false,
            'conflict' => ! hash_equals($existing->request_hash, $requestHash),
            'management_token' => null,
        ];
    }

    private function requestHash(string $longUrl, ?CarbonImmutable $expiresAt): string
    {
        if ($expiresAt === null) {
            return hash('sha256', $longUrl);
        }

        return hash('sha256', json_encode([
            'long_url' => $longUrl,
            'expires_at' => $expiresAt->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
