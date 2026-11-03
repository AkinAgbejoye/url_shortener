<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Models\CrawlPage;
use App\Models\ModerationDomainBlock;
use App\Models\MonitorRun;
use App\Models\MonitorVersion;
use App\Services\BoundedSiteCrawler;
use App\Services\MonitorComparisonService;
use App\Services\MonitoringNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunScheduledMonitor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $runId) {}

    public function middleware(): array
    {
        $scheduleId = MonitorRun::query()->whereKey($this->runId)->value('monitor_schedule_id') ?? $this->runId;

        return [(new WithoutOverlapping('monitor-schedule:'.$scheduleId))->expireAfter(360)];
    }

    public function handle(BoundedSiteCrawler $crawler, MonitorComparisonService $comparisons, MonitoringNotifier $notifier): void
    {
        $run = MonitorRun::query()->with('schedule.verification', 'schedule.owner')->find($this->runId);
        if ($run === null || $run->state !== 'queued') {
            return;
        }
        try {
            $schedule = $run->schedule;
            $schedule->refresh()->load('verification', 'owner');
            $monthRuns = $schedule->runs()->where('scheduled_for', '>=', $run->scheduled_for->startOfMonth())->count();
            if (! (bool) config('website_intelligence.monitoring.enabled', true) || $schedule->state !== 'active' || $schedule->deleted_at !== null
                || ! $schedule->verification->current() || ! $schedule->owner->hasVerifiedEmail() || $schedule->owner->intelligence_suspended_at !== null
                || $monthRuns > $schedule->monthly_run_budget
                || ModerationDomainBlock::query()->where('domain_hash', $schedule->verification->domain_hash)->whereNull('released_at')->exists()) {
                throw new RuntimeException('authorization_or_budget');
            }
            if ($run->cancel_requested_at !== null) {
                throw new RuntimeException('cancelled');
            }
            $run->forceFill(['state' => 'running', 'started_at' => now()])->save();
            $crawl = CrawlJob::create([
                'owner_id' => $schedule->owner_id, 'domain_verification_id' => $schedule->domain_verification_id,
                'public_id' => 'crawl_'.Str::ulid(), 'state' => 'running', 'policy' => $schedule->crawl_policy,
                'started_at' => now(), 'expires_at' => now()->addDays(max(1, (int) config('website_intelligence.budgets.retention_days', 30))),
            ]);
            $run->forceFill(['crawl_job_id' => $crawl->id])->save();
            $summary = $crawler->crawl($crawl);
            $run->refresh();
            if ($run->cancel_requested_at !== null) {
                throw new RuntimeException('cancelled');
            }
            $crawl->forceFill(['state' => 'completed', 'summary' => $summary, 'completed_at' => now()])->save();
            $snapshot = $this->aggregate($crawl->pages()->orderBy('id')->get()->all(), $schedule->parser_version, $schedule->model_version);
            $previous = $schedule->versions()->latest('sequence')->first();
            $version = MonitorVersion::create([
                'monitor_schedule_id' => $schedule->id, 'monitor_run_id' => $run->id,
                'public_id' => 'version_'.Str::ulid(), 'sequence' => ($previous?->sequence ?? 0) + 1,
                'source_snapshot' => $snapshot,
                'evidence_references' => $crawl->pages()->orderBy('id')->get()->map(fn ($page): array => ['page_id' => $page->public_id, 'content_hash' => $page->content_hash, 'captured_at' => $page->fetched_at->toISOString()])->all(),
                'parser_version' => $schedule->parser_version, 'model_version' => $schedule->model_version, 'captured_at' => now(),
            ]);
            if ($previous !== null) {
                $comparison = $comparisons->compare($previous, $version, (int) $schedule->thresholds['minimum_changed_categories']);
                $notifier->notify($schedule, $run, $comparison);
            }
            $run->forceFill(['state' => 'completed', 'completed_at' => now(), 'safe_failure_category' => null])->save();
            $schedule->forceFill(['last_run_at' => now()])->save();
        } catch (Throwable $exception) {
            $cancelled = $exception->getMessage() === 'cancelled';
            if ($run->crawl_job_id !== null) {
                $run->crawlJob?->forceFill(['state' => $cancelled ? 'cancelled' : 'failed', 'completed_at' => now()])->save();
            }
            $run->forceFill(['state' => $cancelled ? 'cancelled' : 'failed', 'completed_at' => now(), 'safe_failure_category' => $cancelled ? null : (in_array($exception->getMessage(), ['authorization_or_budget', 'authorization_expired'], true) ? 'authorization_or_budget' : 'monitor_run_failed')])->save();
        }
    }

    /** @param list<CrawlPage> $pages @return array<string, array{hash: string, count: int}> */
    private function aggregate(array $pages, string $parserVersion, string $modelVersion): array
    {
        $snapshot = [];
        foreach (['content', 'pricing', 'calls_to_action', 'structured_data'] as $category) {
            $hashes = array_map(fn ($page): string => (string) ($page->evidence_snapshot[$category]['hash'] ?? ''), $pages);
            $count = array_sum(array_map(fn ($page): int => (int) ($page->evidence_snapshot[$category]['count'] ?? 0), $pages));
            $snapshot[$category] = ['hash' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)), 'count' => $count];
        }
        $snapshot['business_assumptions'] = ['hash' => hash('sha256', $snapshot['content']['hash'].'|'.$snapshot['structured_data']['hash'].'|'.$modelVersion), 'count' => $snapshot['content']['count'] > 0 ? 1 : 0];
        $snapshot['recommendations'] = ['hash' => hash('sha256', $snapshot['pricing']['hash'].'|'.$snapshot['calls_to_action']['hash'].'|'.$parserVersion.'|'.$modelVersion), 'count' => 1];

        return $snapshot;
    }
}
