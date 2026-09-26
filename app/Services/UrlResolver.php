<?php

namespace App\Services;

use App\Models\Url;
use App\Support\OperationalMetrics;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlResolver
{
    public function __construct(private readonly OperationalMetrics $metrics) {}

    public function resolve(string $shortCode): ?string
    {
        $cacheKey = "url:{$shortCode}";
        $cacheOperation = 'read';

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->metrics->cache('read', 'hit');

                return $cached;
            }
            $this->metrics->cache('read', 'miss');

            $cacheOperation = 'lock';
            $resolved = Cache::lock("lock:cache-rebuild:{$shortCode}", 10)
                ->block(1, function () use (&$cacheOperation, $cacheKey, $shortCode): ?string {
                    $cacheOperation = 'read';
                    $cached = Cache::get($cacheKey);
                    if (is_string($cached) && $cached !== '') {
                        $this->metrics->cache('read', 'hit');

                        return $cached;
                    }
                    $this->metrics->cache('read', 'miss');

                    return $this->resolveFromDatabase($shortCode, $cacheKey, $cacheOperation);
                });

            return $resolved;
        } catch (LockTimeoutException $exception) {
            $this->metrics->cache('lock', 'failure');
            Log::warning('url_cache_lock_timeout', [
                'short_code' => $shortCode,
                'exception' => $exception->getMessage(),
                ...$this->requestContext(),
            ]);
        } catch (Throwable $exception) {
            $this->metrics->cache($cacheOperation, 'failure');
            Log::warning('url_cache_operation_failed', [
                'short_code' => $shortCode,
                'exception' => $exception->getMessage(),
                ...$this->requestContext(),
            ]);
        }

        return Url::where('short_code', $shortCode)->value('long_url');
    }

    private function resolveFromDatabase(
        string $shortCode,
        string $cacheKey,
        string &$cacheOperation,
    ): ?string {
        $longUrl = Url::where('short_code', $shortCode)->value('long_url');
        if ($longUrl !== null) {
            $cacheOperation = 'write';
            Cache::put($cacheKey, $longUrl, now()->addHours(24));
            $this->metrics->cache('write', 'success');
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
