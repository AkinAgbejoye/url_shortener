<?php

namespace App\Services;

use App\Models\Url;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UrlManagementService
{
    public function __construct(private readonly UrlCache $urlCache) {}

    public function inspect(string $shortCode, ?string $token, ?User $user = null): Url
    {
        return $this->authorizedUrl($shortCode, $token, $user);
    }

    public function updateExpiration(
        string $shortCode,
        ?string $token,
        ?CarbonImmutable $expiresAt,
        ?User $user = null,
    ): Url {
        return $this->mutate($shortCode, $token, $user, function (Url $url) use ($expiresAt): void {
            $url->update(['expires_at' => $expiresAt]);
        });
    }

    public function disable(string $shortCode, ?string $token, ?User $user = null): Url
    {
        return $this->mutate($shortCode, $token, $user, function (Url $url): void {
            $url->update(['disabled_at' => CarbonImmutable::now('UTC')]);
        });
    }

    public function enable(string $shortCode, ?string $token, ?User $user = null): Url
    {
        return $this->mutate($shortCode, $token, $user, function (Url $url): void {
            $url->update(['disabled_at' => null]);
        });
    }

    public function delete(string $shortCode, ?string $token, ?User $user = null): void
    {
        $this->mutate($shortCode, $token, $user, function (Url $url): void {
            $url->delete();
        });
    }

    public function claim(string $shortCode, ?string $token, User $user): Url
    {
        return DB::transaction(function () use ($shortCode, $token, $user): Url {
            $url = $this->anonymousTokenUrl($shortCode, $token, true);
            $url->update([
                'owner_id' => $user->getKey(),
                'management_token_hash' => null,
            ]);
            $this->urlCache->forget($shortCode);

            return $url->refresh();
        });
    }

    private function mutate(string $shortCode, ?string $token, ?User $user, callable $mutation): Url
    {
        return DB::transaction(function () use ($shortCode, $token, $user, $mutation): Url {
            $url = $this->authorizedUrl($shortCode, $token, $user, true);
            $mutation($url);
            $this->urlCache->forget($shortCode);

            return $url->trashed() ? $url : $url->refresh();
        });
    }

    private function authorizedUrl(string $shortCode, ?string $token, ?User $user = null, bool $lock = false): Url
    {
        $query = Url::query()->where('short_code', $shortCode);
        if ($lock) {
            $query->lockForUpdate();
        }

        $url = $query->first();
        if ($url === null) {
            throw (new ModelNotFoundException)->setModel(Url::class);
        }

        if ($url->isOwned()) {
            if ($user !== null && $url->owner_id === $user->getKey()) {
                return $url;
            }

            throw (new ModelNotFoundException)->setModel(Url::class);
        }

        $this->assertValidToken($url, $token);

        return $url;
    }

    private function anonymousTokenUrl(string $shortCode, ?string $token, bool $lock = false): Url
    {
        $query = Url::query()->where('short_code', $shortCode)->whereNull('owner_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $url = $query->first();
        if ($url === null) {
            throw (new ModelNotFoundException)->setModel(Url::class);
        }

        $this->assertValidToken($url, $token);

        return $url;
    }

    private function assertValidToken(Url $url, ?string $token): void
    {
        if (! is_string($token)
            || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
            || $url->management_token_hash === null
            || ! hash_equals($url->management_token_hash, hash('sha256', $token))) {
            throw (new ModelNotFoundException)->setModel(Url::class);
        }
    }
}
