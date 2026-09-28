<?php

namespace App\Services;

use App\Support\OperationalMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyticsPruner
{
    public function __construct(private readonly OperationalMetrics $metrics) {}

    /**
     * Delete daily buckets dated strictly before the UTC cutoff date, one bounded batch at a time.
     *
     * @return array{examined: int, deleted: int, skipped: int, failed: int}
     */
    public function prune(CarbonImmutable $cutoffDate, int $batchSize, bool $dryRun): array
    {
        $counts = ['examined' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];
        $cutoff = $cutoffDate->toDateString();
        $batch = 0;

        DB::table('url_analytics_daily')
            ->select('id')
            ->where('date', '<', $cutoff)
            ->chunkById($batchSize, function ($buckets) use (&$counts, &$batch, $cutoff, $dryRun): void {
                $batch++;
                $ids = $buckets->pluck('id')->all();
                $examined = count($ids);
                $counts['examined'] += $examined;

                if ($dryRun) {
                    $counts['skipped'] += $examined;
                    $this->metrics->analyticsCleanup('batch', 'skipped');

                    return;
                }

                try {
                    $deleted = DB::table('url_analytics_daily')
                        ->whereIn('id', $ids)
                        ->where('date', '<', $cutoff)
                        ->delete();
                    $counts['deleted'] += $deleted;
                    $counts['skipped'] += $examined - $deleted;
                    $this->metrics->analyticsCleanup('batch', 'deleted');
                } catch (Throwable $exception) {
                    $counts['failed'] += $examined;
                    $this->metrics->analyticsCleanup('batch', 'failed');
                    Log::error('url_analytics_cleanup_batch_failed', [
                        'batch' => $batch,
                        'examined' => $examined,
                        'deleted' => 0,
                        'skipped' => 0,
                        'failed' => $examined,
                        'exception_class' => $exception::class,
                    ]);
                }
            });

        return $counts;
    }

    public function recordRun(bool $succeeded): void
    {
        $this->metrics->analyticsCleanup('run', $succeeded ? 'succeeded' : 'failed');
    }
}
