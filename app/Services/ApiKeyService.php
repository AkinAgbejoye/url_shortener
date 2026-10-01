<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ApiKeyService
{
    /**
     * @param  list<string>  $scopes
     * @return array{api_key: ApiKey, plain_text_key: string}
     */
    public function create(User $user, string $name, array $scopes, ?string $expiresAt): array
    {
        return DB::transaction(function () use ($user, $name, $scopes, $expiresAt): array {
            $maxActive = (int) config('url_shortener.api_keys.max_active_per_user', 10);
            $activeCount = ApiKey::query()->whereBelongsTo($user)->active()->lockForUpdate()->count();

            if ($activeCount >= $maxActive) {
                throw new RuntimeException("You can have up to {$maxActive} active API keys.");
            }

            $publicId = $this->newPublicId();
            $secret = Str::random(64);
            $plainTextKey = "{$publicId}.{$secret}";

            $apiKey = ApiKey::create([
                'user_id' => $user->id,
                'name' => $name,
                'public_id' => $publicId,
                'secret_hash' => Hash::make($secret),
                'scopes' => array_values($scopes),
                'expires_at' => $expiresAt,
            ]);

            return ['api_key' => $apiKey, 'plain_text_key' => $plainTextKey];
        });
    }

    public function revoke(ApiKey $apiKey): ApiKey
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
        }

        return $apiKey->refresh();
    }

    private function newPublicId(): string
    {
        do {
            $publicId = 'ak_'.Str::lower(Str::random(20));
        } while (ApiKey::query()->where('public_id', $publicId)->exists());

        return $publicId;
    }
}
