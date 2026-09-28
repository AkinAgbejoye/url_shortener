<?php

namespace App\Services;

use App\Models\Url;
use App\Support\OperationalMetrics;
use App\Support\UrlResolution;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class UrlResolver
{
    public function __construct(
        private readonly OperationalMetrics $metrics,
        private readonly UrlCache $urlCache,
    ) {}

    public function resolve(string $shortCode): UrlResolution
    {
        $cacheOperation = 'read';

        try {
            $cached = $this->urlCache->get($shortCode);
            if ($cached !== null) {
                $this->metrics->cache('read', 'hit');

                return UrlResolution::found($cached['long_url'], $cached['url_id']);
            }
            $this->metrics->cache('read', 'miss');

            $cacheOperation = 'lock';
            $resolved = Cache::lock("lock:cache-rebuild:{$shortCode}", 10)
                ->block(1, function () use (&$cacheOperation, $shortCode): UrlResolution {
                    $cacheOperation = 'read';
                    $cached = $this->urlCache->get($shortCode);
                    if ($cached !== null) {
                        $this->metrics->cache('read', 'hit');

                        return UrlResolution::found($cached['long_url'], $cached['url_id']);
                    }
                    $this->metrics->cache('read', 'miss');

                    return $this->resolveFromDatabase($shortCode, $cacheOperation);
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

        return $this->resolveFromDatabase($shortCode, $cacheOperation, false);
    }

    private function resolveFromDatabase(
        string $shortCode,
        string &$cacheOperation,
        bool $writeCache = true,
    ): UrlResolution {
        $url = Url::withTrashed()->where('short_code', $shortCode)->first();
        if ($url === null) {
            return UrlResolution::missing();
        }

        $state = $url->lifecycleState();
        if (! $url->isActive()) {
            return UrlResolution::unavailable($state);
        }

        if ($writeCache) {
            $cacheOperation = 'write';
            $this->urlCache->put($shortCode, $url->id, $url->long_url, $url->expires_at);
            $this->metrics->cache('write', 'success');
        }

        return UrlResolution::found($url->long_url, $url->id);
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
