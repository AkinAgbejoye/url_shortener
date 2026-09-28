<?php

namespace App\Console\Commands;

use App\Rules\AnalyticsRange;
use App\Services\AnalyticsPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneUrlAnalytics extends Command
{
    private const MAX_BATCH_SIZE = 1000;

    private const MAX_RETENTION_DAYS = 3650;

    protected $signature = 'urls:prune-analytics
        {--dry-run : Report eligible buckets without deleting them}
        {--batch-size= : Buckets deleted per database batch}
        {--retention-days= : Whole UTC days of analytics kept before today}';

    protected $description = 'Prune daily URL analytics buckets older than the retention cutoff';

    public function handle(AnalyticsPruner $pruner): int
    {
        $batchSize = filter_var(
            $this->option('batch-size') ?? config('url_shortener.analytics.cleanup.batch_size'),
            FILTER_VALIDATE_INT,
        );
        $retentionDays = filter_var(
            $this->option('retention-days') ?? config('url_shortener.analytics.retention_days'),
            FILTER_VALIDATE_INT,
        );
        $minimumRetention = AnalyticsRange::maximumDays();

        if ($batchSize === false || $batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            $this->error('The batch size must be an integer between 1 and 1,000.');

            return self::FAILURE;
        }

        if ($retentionDays === false || $retentionDays < $minimumRetention || $retentionDays > self::MAX_RETENTION_DAYS) {
            $this->error(sprintf(
                'The retention period must be an integer between %d (the analytics query limit) and %s days.',
                $minimumRetention,
                number_format(self::MAX_RETENTION_DAYS),
            ));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoffDate = CarbonImmutable::now('UTC')->startOfDay()->subDays($retentionDays);
        $counts = $pruner->prune($cutoffDate, $batchSize, $dryRun);
        $succeeded = $counts['failed'] === 0;

        $pruner->recordRun($succeeded);
        Log::info('url_analytics_cleanup_completed', [
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'retention_days' => $retentionDays,
            'cutoff_date' => $cutoffDate->toDateString(),
            ...$counts,
        ]);
        $this->table(['Examined', 'Deleted', 'Skipped', 'Failed'], [[
            $counts['examined'],
            $counts['deleted'],
            $counts['skipped'],
            $counts['failed'],
        ]]);

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }
}
