<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class BoundAnalysisConcurrency
{
    public function handle(object $job, Closure $next): void
    {
        $limit = max(1, (int) config('website_intelligence.budgets.max_concurrent_jobs', 4));
        $ttl = max(2, (int) config('website_intelligence.budgets.max_job_runtime_seconds', 120) + 10);
        $start = property_exists($job, 'analysisJobId') ? ((int) $job->analysisJobId % $limit) : 0;
        $lock = null;

        for ($offset = 0; $offset < $limit; $offset++) {
            $candidate = Cache::lock('website-intelligence:acquisition-slot:'.(($start + $offset) % $limit), $ttl);
            if ($candidate->get()) {
                $lock = $candidate;

                break;
            }
        }

        if (! $lock instanceof Lock) {
            if (method_exists($job, 'release')) {
                $job->release(1);
            }

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
