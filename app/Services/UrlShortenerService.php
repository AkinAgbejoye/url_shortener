<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\Url;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UrlShortenerService
{
    public function __construct(private readonly Base62Service $base62) {}

    /** @return array{response: array<string, mixed>, created: bool, conflict: bool} */
    public function shorten(string $longUrl, ?string $idempotencyKey): array
    {
        $requestHash = hash('sha256', $longUrl);

        if ($idempotencyKey !== null) {
            $existing = IdempotencyKey::where('key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->replay($existing, $requestHash);
            }
        }

        try {
            return DB::transaction(function () use ($longUrl, $idempotencyKey, $requestHash): array {
                $url = Url::create([
                    'long_url' => $longUrl,
                    'short_code' => 'pending-'.Str::uuid(),
                ]);
                $shortCode = $this->base62->encode($url->id);
                $url->update(['short_code' => $shortCode]);

                $response = [
                    'id' => $url->id,
                    'short_code' => $shortCode,
                    'short_url' => url('/'.$shortCode),
                    'long_url' => $url->long_url,
                ];

                if ($idempotencyKey !== null) {
                    IdempotencyKey::create([
                        'key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'response' => $response,
                    ]);
                }

                return ['response' => $response, 'created' => true, 'conflict' => false];
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

    /** @return array{response: array<string, mixed>, created: bool, conflict: bool} */
    private function replay(IdempotencyKey $existing, string $requestHash): array
    {
        return [
            'response' => $existing->response,
            'created' => false,
            'conflict' => ! hash_equals($existing->request_hash, $requestHash),
        ];
    }
}
