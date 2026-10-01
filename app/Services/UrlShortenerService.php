<?php

namespace App\Services;

use App\Exceptions\CustomAliasConflict;
use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Models\User;
use App\Support\CustomAlias;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UrlShortenerService
{
    public function __construct(private readonly ShortCodeAllocator $shortCodes) {}

    /** @return array{response: array<string, mixed>, created: bool, conflict: bool, management_token: string|null} */
    public function shorten(
        string $longUrl,
        ?string $idempotencyKey,
        ?CarbonImmutable $expiresAt = null,
        ?string $customAlias = null,
        ?User $owner = null,
    ): array {
        $customAlias = $this->canonicalAlias($customAlias);
        $requestHash = $this->requestHash($longUrl, $expiresAt, $customAlias, $owner);

        if ($idempotencyKey !== null) {
            $existing = IdempotencyKey::where('key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->replay($existing, $requestHash);
            }
        }

        try {
            return DB::transaction(function () use ($longUrl, $idempotencyKey, $requestHash, $expiresAt, $customAlias, $owner): array {
                $managementToken = $owner === null ? bin2hex(random_bytes(32)) : null;
                $url = Url::create([
                    'owner_id' => $owner?->getKey(),
                    'long_url' => $longUrl,
                    'short_code' => 'pending-'.Str::uuid(),
                    'expires_at' => $expiresAt,
                    'management_token_hash' => $managementToken === null ? null : hash('sha256', $managementToken),
                ]);
                $shortCode = $this->shortCodes->claim($url, $customAlias);

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
        } catch (CustomAliasConflict $exception) {
            $existing = $idempotencyKey === null
                ? null
                : IdempotencyKey::where('key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
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

    private function requestHash(
        string $longUrl,
        ?CarbonImmutable $expiresAt,
        ?string $customAlias,
        ?User $owner,
    ): string {
        if ($expiresAt === null && $customAlias === null && $owner === null) {
            return hash('sha256', $longUrl);
        }

        $intent = ['long_url' => $longUrl];

        if ($expiresAt !== null) {
            $intent['expires_at'] = $expiresAt->utc()->toIso8601String();
        }

        if ($customAlias !== null) {
            $intent['custom_alias'] = $customAlias;
        }

        if ($owner !== null) {
            $intent['owner_id'] = $owner->getKey();
        }

        return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
    }

    private function canonicalAlias(?string $customAlias): ?string
    {
        if ($customAlias === null) {
            return null;
        }

        $canonical = CustomAlias::canonicalize($customAlias);

        return $canonical === '' ? null : $canonical;
    }
}
