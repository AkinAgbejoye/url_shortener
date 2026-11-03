<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Services\BoundedSiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CrawlVerifiedDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly int $crawlJobId)
    {
        $this->timeout = max(1, (int) config('website_intelligence.crawling.max_runtime_seconds', 300));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('crawl-job:'.$this->crawlJobId))->expireAfter($this->timeout + 10)];
    }

    public function handle(BoundedSiteCrawler $crawler): void
    {
        $job = CrawlJob::query()->with('verification')->find($this->crawlJobId);
        if ($job === null || ! in_array($job->state, ['queued', 'running'], true)) {
            return;
        }
        if ($job->cancel_requested_at !== null) {
            $job->forceFill(['state' => 'cancelled', 'completed_at' => now()])->save();

            return;
        }
        $job->forceFill(['state' => 'running', 'started_at' => $job->started_at ?? now()])->save();
        try {
            $summary = $crawler->crawl($job);
            $job->refresh();
            $state = $job->cancel_requested_at !== null ? 'cancelled' : ($job->state === 'paused' ? 'paused' : 'completed');
            $job->forceFill(['state' => $state, 'summary' => $summary, 'completed_at' => $state === 'paused' ? null : now()])->save();
        } catch (Throwable $exception) {
            $category = in_array($exception->getMessage(), ['authorization_expired', 'robots_unavailable'], true) ? $exception->getMessage() : 'crawl_failed';
            $job->forceFill(['state' => 'failed', 'summary' => ['safe_failure_category' => $category], 'completed_at' => now()])->save();
        }
    }
}
