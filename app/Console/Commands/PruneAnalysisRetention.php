<?php

namespace App\Console\Commands;

use App\Services\AnalysisRetentionPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneAnalysisRetention extends Command
{
    protected $signature = 'intelligence:prune-retention
        {--dry-run : Report eligible records without changing them}
        {--batch-size= : Records loaded per database batch}
        {--metadata-days= : Days expired job metadata remains available}';

    protected $description = 'Expire retained analysis evidence and prune old metadata';

    public function handle(AnalysisRetentionPruner $pruner): int
    {
        $batchSize = filter_var($this->option('batch-size') ?? config('website_intelligence.reports.cleanup_batch_size'), FILTER_VALIDATE_INT);
        $metadataDays = filter_var($this->option('metadata-days') ?? config('website_intelligence.reports.expired_metadata_days'), FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 1000 || $metadataDays === false || $metadataDays < 1) {
            $this->error('Batch size must be 1-1,000 and metadata days must be positive.');

            return self::FAILURE;
        }

        $counts = $pruner->prune(CarbonImmutable::now('UTC'), $metadataDays, $batchSize, (bool) $this->option('dry-run'));
        Log::info('analysis_retention_cleanup_completed', [
            'dry_run' => (bool) $this->option('dry-run'),
            'batch_size' => $batchSize,
            'metadata_days' => $metadataDays,
            ...$counts,
        ]);
        $this->table(['Examined', 'Expired', 'Deleted', 'Failed'], [array_values($counts)]);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
