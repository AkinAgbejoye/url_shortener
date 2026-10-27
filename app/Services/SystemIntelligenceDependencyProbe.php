<?php

namespace App\Services;

use App\Contracts\IntelligenceDependencyProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SystemIntelligenceDependencyProbe implements IntelligenceDependencyProbe
{
    public function check(): array
    {
        return [
            'database' => $this->measure(fn (): bool => DB::select('select 1') !== []),
            'queue' => $this->measure(fn (): bool => $this->queueConfigured()),
            'cache' => $this->measure(fn (): bool => $this->cacheRoundTrip()),
            'lock' => $this->measure(fn (): bool => $this->lockRoundTrip()),
            'storage' => $this->measure(fn (): bool => $this->storageRoundTrip()),
            'worker' => $this->worker(),
        ];
    }

    /** @return array{status: 'ready'|'not_ready', duration_ms: float} */
    private function measure(callable $check): array
    {
        $startedAt = hrtime(true);

        try {
            $ready = $check() === true;
        } catch (Throwable) {
            $ready = false;
        }

        return [
            'status' => $ready ? 'ready' : 'not_ready',
            'duration_ms' => max(0, (hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    private function queueConfigured(): bool
    {
        $connection = (string) config('queue.default');

        return $connection !== ''
            && config("queue.connections.{$connection}") !== null
            && Queue::connection($connection)->size() >= 0;
    }

    private function cacheRoundTrip(): bool
    {
        $key = 'website-intelligence:readiness:cache';
        Cache::put($key, 'ready', 5);
        $ready = Cache::get($key) === 'ready';
        Cache::forget($key);

        return $ready;
    }

    private function lockRoundTrip(): bool
    {
        $lock = Cache::lock('website-intelligence:readiness:lock', 5);
        $acquired = $lock->get();
        if ($acquired) {
            $lock->release();
        }

        return $acquired;
    }

    private function storageRoundTrip(): bool
    {
        $disk = Storage::disk((string) config('website_intelligence.readiness.storage_disk', 'local'));
        $path = 'readiness/website-intelligence-probe';
        $written = $disk->put($path, 'ready');
        $ready = $written && $disk->get($path) === 'ready';
        $disk->delete($path);

        return $ready;
    }

    /** @return array{status: 'ready'|'not_ready'|'disabled', duration_ms: float} */
    private function worker(): array
    {
        $startedAt = hrtime(true);
        if (config('queue.default') === 'sync') {
            return ['status' => 'disabled', 'duration_ms' => 0.0];
        }

        try {
            $heartbeat = Cache::get((string) config('website_intelligence.readiness.worker_heartbeat_key'));
            $fresh = is_int($heartbeat) && $heartbeat >= now()->subSeconds(
                (int) config('website_intelligence.readiness.worker_stale_seconds', 90),
            )->getTimestamp();
        } catch (Throwable) {
            $fresh = false;
        }

        return [
            'status' => $fresh ? 'ready' : 'not_ready',
            'duration_ms' => max(0, (hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
