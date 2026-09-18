<?php

namespace App\Services;

use App\Models\Url;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlResolver
{
    public function resolve(string $shortCode): ?string
    {
        $cacheKey = "url:{$shortCode}";

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return Cache::lock("lock:cache-rebuild:{$shortCode}", 10)
                ->block(1, function () use ($cacheKey, $shortCode): ?string {
                    $cached = Cache::get($cacheKey);
                    if (is_string($cached) && $cached !== '') {
                        return $cached;
                    }

                    return $this->resolveFromDatabase($shortCode, $cacheKey);
                });
        } catch (LockTimeoutException $exception) {
            Log::warning('url_cache_lock_timeout', [
                'short_code' => $shortCode,
                'exception' => $exception->getMessage(),
                ...$this->requestContext(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('url_cache_operation_failed', [
                'short_code' => $shortCode,
                'exception' => $exception->getMessage(),
                ...$this->requestContext(),
            ]);
        }

        return Url::where('short_code', $shortCode)->value('long_url');
    }

    private function resolveFromDatabase(string $shortCode, string $cacheKey): ?string
    {
        $longUrl = Url::where('short_code', $shortCode)->value('long_url');
        if ($longUrl !== null) {
            Cache::put($cacheKey, $longUrl, now()->addHours(24));
        }

        return $longUrl;
    }

    /** @return array{request_id: mixed, url_path: string|null} */
    private function requestContext(): array
    {
        return [
            'request_id' => request()?->attributes->get('request_id'),
            'url_path' => request()?->path(),
        ];
    }
}
