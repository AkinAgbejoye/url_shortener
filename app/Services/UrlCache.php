<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

class UrlCache
{
    private const VERSION = 1;

    public function get(string $shortCode): ?string
    {
        $value = Cache::get($this->key($shortCode));

        if (! is_array($value)
            || ($value['version'] ?? null) !== self::VERSION
            || ! is_string($value['long_url'] ?? null)
            || ! $this->hasSafeDestination($value['long_url'])
            || ! $this->hasValidExpiration($value['expires_at'] ?? null)) {
            if ($value !== null) {
                Cache::forget($this->key($shortCode));
            }

            return null;
        }

        return $value['long_url'];
    }

    public function put(
        string $shortCode,
        string $longUrl,
        ?DateTimeInterface $expiresAt = null,
    ): void {
        $expiration = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt);
        $ttl = CarbonImmutable::now()->addHours(24);

        if ($expiration !== null && $expiration->lessThan($ttl)) {
            $ttl = $expiration;
        }

        if ($ttl->isPast()) {
            $this->forget($shortCode);

            return;
        }

        Cache::put($this->key($shortCode), [
            'version' => self::VERSION,
            'long_url' => $longUrl,
            'expires_at' => $expiration?->toIso8601String(),
        ], $ttl);
    }

    public function forget(string $shortCode): void
    {
        Cache::forget($this->key($shortCode));
    }

    private function key(string $shortCode): string
    {
        return "url:{$shortCode}";
    }

    private function hasSafeDestination(string $destination): bool
    {
        $scheme = parse_url($destination, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    private function hasValidExpiration(mixed $expiresAt): bool
    {
        if ($expiresAt === null) {
            return true;
        }

        if (! is_string($expiresAt)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }
}
