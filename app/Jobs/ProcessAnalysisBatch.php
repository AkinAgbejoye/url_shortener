<?php

namespace App\Jobs;

use App\Exceptions\AcquisitionPolicyViolation;
use App\Models\AnalysisBatch;
use App\Models\AnalysisBatchRow;
use App\Services\AnalysisJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAnalysisBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $batchId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('analysis-batch:'.$this->batchId))->expireAfter(310)];
    }

    public function handle(AnalysisJobService $analyses): void
    {
        $batch = AnalysisBatch::query()->find($this->batchId);
        if ($batch === null || ! in_array($batch->state, ['queued', 'running'], true)) {
            return;
        }
        $batch->forceFill(['state' => 'running'])->save();
        foreach ($batch->manifest_rows as $index => $row) {
            $batch->refresh();
            if ($batch->cancel_requested_at !== null) {
                $batch->forceFill(['state' => 'cancelled', 'completed_at' => now()])->save();

                return;
            }
            $rowNumber = $index + 1;
            if (AnalysisBatchRow::query()->where('analysis_batch_id', $batch->id)->where('row_number', $rowNumber)->where('status', 'created')->exists()) {
                continue;
            }
            try {
                $owner = $batch->owner()->firstOrFail();
                if (! $owner->hasVerifiedEmail()) {
                    throw new AcquisitionPolicyViolation('owner_unverified');
                }
                $result = $analyses->create($owner, $row['url'], $row['authorization_attestation'], $row['user_context'], 'batch-'.$batch->public_id.'-'.$rowNumber);
                AnalysisBatchRow::query()->updateOrCreate(['analysis_batch_id' => $batch->id, 'row_number' => $rowNumber], ['status' => 'created', 'safe_error' => null, 'analysis_job_id' => $result['job']->id]);
                $batch->increment('successful_rows');
            } catch (Throwable $exception) {
                $safe = str_contains($exception::class, 'Quota') ? 'quota_or_budget' : (str_contains($exception::class, 'Policy') ? 'policy_or_validation' : 'row_failed');
                AnalysisBatchRow::query()->updateOrCreate(['analysis_batch_id' => $batch->id, 'row_number' => $rowNumber], ['status' => 'failed', 'safe_error' => $safe, 'analysis_job_id' => null]);
                $batch->increment('failed_rows');
            }
            $batch->increment('processed_rows');
        }
        $batch->refresh()->forceFill(['state' => $batch->successful_rows > 0 ? ($batch->failed_rows > 0 ? 'partially_completed' : 'completed') : 'failed', 'completed_at' => now()])->save();
    }
}
