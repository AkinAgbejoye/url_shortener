<?php

namespace App\Services;

use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UrlManagementService
{
    public function __construct(private readonly UrlCache $urlCache) {}

    public function inspect(string $shortCode, ?string $token): Url
    {
        return $this->authorizedUrl($shortCode, $token);
    }

    public function updateExpiration(
        string $shortCode,
        ?string $token,
        ?CarbonImmutable $expiresAt,
    ): Url {
        return $this->mutate($shortCode, $token, function (Url $url) use ($expiresAt): void {
            $url->update(['expires_at' => $expiresAt]);
        });
    }

    public function disable(string $shortCode, ?string $token): Url
    {
        return $this->mutate($shortCode, $token, function (Url $url): void {
            $url->update(['disabled_at' => CarbonImmutable::now('UTC')]);
        });
    }

    public function enable(string $shortCode, ?string $token): Url
    {
        return $this->mutate($shortCode, $token, function (Url $url): void {
            $url->update(['disabled_at' => null]);
        });
    }

    public function delete(string $shortCode, ?string $token): void
    {
        $this->mutate($shortCode, $token, function (Url $url): void {
            $url->delete();
        });
    }

    private function mutate(string $shortCode, ?string $token, callable $mutation): Url
    {
        return DB::transaction(function () use ($shortCode, $token, $mutation): Url {
            $url = $this->authorizedUrl($shortCode, $token, true);
            $mutation($url);
            $this->urlCache->forget($shortCode);

            return $url->trashed() ? $url : $url->refresh();
        });
    }

    private function authorizedUrl(string $shortCode, ?string $token, bool $lock = false): Url
    {
        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw (new ModelNotFoundException)->setModel(Url::class);
        }

        $query = Url::query()->where('short_code', $shortCode);
        if ($lock) {
            $query->lockForUpdate();
        }

        $url = $query->first();
        if ($url === null
            || $url->management_token_hash === null
            || ! hash_equals($url->management_token_hash, hash('sha256', $token))) {
            throw (new ModelNotFoundException)->setModel(Url::class);
        }

        return $url;
    }
}
