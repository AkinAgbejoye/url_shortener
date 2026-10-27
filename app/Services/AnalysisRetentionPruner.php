<?php

namespace App\Services;

use App\Enums\AnalysisState;
use App\Models\AnalysisJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalysisRetentionPruner
{
    /** @return array{examined: int, expired: int, deleted: int, failed: int} */
    public function prune(CarbonImmutable $now, int $metadataDays, int $batchSize, bool $dryRun): array
    {
        $counts = ['examined' => 0, 'expired' => 0, 'deleted' => 0, 'failed' => 0];
        $deleteBefore = $now->subDays($metadataDays);

        AnalysisJob::query()->where('expires_at', '<=', $now)->orderBy('id')->chunkById($batchSize, function ($jobs) use (&$counts, $deleteBefore, $dryRun): void {
            foreach ($jobs as $job) {
                $counts['examined']++;
                try {
                    if ($job->state === AnalysisState::Expired && $job->expires_at->lte($deleteBefore)) {
                        if (! $dryRun) {
                            $job->delete();
                        }
                        $counts['deleted']++;

                        continue;
                    }
                    if ($job->state !== AnalysisState::Expired) {
                        if (! $dryRun) {
                            DB::transaction(function () use ($job): void {
                                $job->observations()->delete();
                                $job->pageFetches()->delete();
                                $job->report()->delete();
                                $job->forceFill([
                                    'state' => AnalysisState::Expired,
                                    'extraction' => null,
                                    'provenance' => null,
                                    'safe_failure_category' => null,
                                ])->save();
                            });
                        }
                        $counts['expired']++;
                    }
                } catch (Throwable) {
                    $counts['failed']++;
                }
            }
        });

        return $counts;
    }
}
