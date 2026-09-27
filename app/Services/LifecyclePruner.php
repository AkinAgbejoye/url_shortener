<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Support\OperationalMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class LifecyclePruner
{
    public function __construct(private readonly OperationalMetrics $metrics) {}

    /** @return array{examined: int, deleted: int, skipped: int, failed: int} */
    public function prune(CarbonImmutable $cutoff, int $batchSize, bool $dryRun): array
    {
        $counts = ['examined' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];

        Url::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->chunkById($batchSize, function ($urls) use (&$counts, $dryRun): void {
                foreach ($urls as $url) {
                    $this->process($url, 'url', $dryRun, fn () => $url->forceDelete(), $counts);
                }
            });

        IdempotencyKey::query()
            ->where('created_at', '<=', $cutoff)
            ->chunkById($batchSize, function ($keys) use (&$counts, $dryRun): void {
                foreach ($keys as $key) {
                    $this->process($key, 'idempotency_key', $dryRun, fn () => $key->delete(), $counts);
                }
            });

        return $counts;
    }

    /**
     * @param  callable(): mixed  $delete
     * @param  array{examined: int, deleted: int, skipped: int, failed: int}  $counts
     */
    private function process(
        Model $record,
        string $recordType,
        bool $dryRun,
        callable $delete,
        array &$counts,
    ): void {
        $counts['examined']++;
        $this->metrics->cleanup($recordType, 'examined');

        if ($dryRun) {
            $counts['skipped']++;
            $this->metrics->cleanup($recordType, 'skipped');

            return;
        }

        try {
            $delete();
            $counts['deleted']++;
            $this->metrics->cleanup($recordType, 'deleted');
        } catch (Throwable $exception) {
            $counts['failed']++;
            $this->metrics->cleanup($recordType, 'failed');
            Log::error('url_lifecycle_cleanup_record_failed', [
                'record_type' => $recordType,
                'record_id' => $record->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
