<?php

namespace App\Console\Commands;

use App\Services\LifecyclePruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneUrlLifecycle extends Command
{
    protected $signature = 'urls:prune-lifecycle
        {--dry-run : Report eligible records without deleting them}
        {--batch-size= : Records loaded per database batch}
        {--retention-days= : Days records remain recoverable before deletion}';

    protected $description = 'Prune retained URL lifecycle and idempotency data safely';

    public function handle(LifecyclePruner $pruner): int
    {
        $batchSize = filter_var(
            $this->option('batch-size') ?? config('url_shortener.cleanup.batch_size'),
            FILTER_VALIDATE_INT,
        );
        $retentionDays = filter_var(
            $this->option('retention-days') ?? config('url_shortener.cleanup.retention_days'),
            FILTER_VALIDATE_INT,
        );

        if ($batchSize === false || $batchSize < 1 || $batchSize > 1000) {
            $this->error('The batch size must be an integer between 1 and 1,000.');

            return self::FAILURE;
        }

        if ($retentionDays === false || $retentionDays < 1) {
            $this->error('The retention period must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now('UTC')->subDays($retentionDays);
        $counts = $pruner->prune($cutoff, $batchSize, $dryRun);
        $context = [
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toIso8601String(),
            ...$counts,
        ];

        Log::info('url_lifecycle_cleanup_completed', $context);
        $this->table(['Examined', 'Deleted', 'Skipped', 'Failed'], [[
            $counts['examined'],
            $counts['deleted'],
            $counts['skipped'],
            $counts['failed'],
        ]]);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
