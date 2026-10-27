<?php

namespace App\Jobs;

use App\Enums\AnalysisState;
use App\Exceptions\AcquisitionFailure;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Jobs\Middleware\BoundAnalysisConcurrency;
use App\Models\AnalysisJob;
use App\Services\AnalysisReportBuilder;
use App\Services\SinglePageAcquirer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AcquireSinglePage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $analysisJobId)
    {
        $this->timeout = max(1, (int) config('website_intelligence.budgets.max_job_runtime_seconds', 120));
    }

    public int $timeout;

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('analysis-job:'.$this->analysisJobId))->expireAfter($this->timeout + 10),
            new BoundAnalysisConcurrency,
        ];
    }

    public function handle(SinglePageAcquirer $acquirer, AnalysisReportBuilder $reportBuilder): void
    {
        $job = AnalysisJob::query()->find($this->analysisJobId);
        if ($job === null || ! $job->state->active()) {
            return;
        }
        if ($job->cancel_requested_at !== null || $job->state === AnalysisState::CancelRequested) {
            $this->cancel($job);

            return;
        }
        if ($job->created_at->lt(now()->subSeconds(max(1, (int) config('website_intelligence.budgets.max_queue_age_seconds', 300))))) {
            $this->failSafely($job, AnalysisState::Failed, 'budget_exceeded');

            return;
        }

        $job->forceFill(['state' => AnalysisState::Fetching, 'started_at' => $job->started_at ?? now()])->save();

        try {
            $result = $acquirer->acquire($job->target_url, function () use ($job): bool {
                $job->refresh();

                return $job->cancel_requested_at !== null || $job->state === AnalysisState::CancelRequested;
            });
            $job->refresh();
            if ($job->cancel_requested_at !== null || $job->state === AnalysisState::CancelRequested) {
                $this->cancel($job);

                return;
            }
            $job->forceFill(['state' => AnalysisState::Analyzing])->save();
            $generated = $reportBuilder->build($job, $result);
            $job->refresh();
            if ($job->cancel_requested_at !== null || $job->state === AnalysisState::CancelRequested) {
                $this->cancel($job);

                return;
            }
            $job->forceFill([
                'state' => $generated['observation_count'] > 0 ? AnalysisState::Completed : AnalysisState::PartiallyCompleted,
                'extraction' => $result['extraction'],
                'provenance' => $result['provenance'],
                'completed_at' => now(),
            ])->save();
        } catch (AcquisitionPolicyViolation) {
            $this->failSafely($job, AnalysisState::Blocked, 'policy_blocked');
        } catch (AcquisitionFailure $exception) {
            if ($exception->category === 'cancelled') {
                $this->cancel($job);

                return;
            }
            if ($exception->category === 'network_failure' && $this->attempts() < $this->tries) {
                $job->forceFill(['state' => AnalysisState::Queued])->save();
                $this->release(1);

                return;
            }
            $this->failSafely($job, AnalysisState::Failed, $this->safeCategory($exception->category));
        } catch (Throwable) {
            $this->failSafely($job, AnalysisState::Failed, 'internal_failure');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = AnalysisJob::query()->find($this->analysisJobId);
        if ($job !== null && $job->state->active()) {
            $this->failSafely($job, AnalysisState::Failed, 'budget_exceeded');
        }
    }

    private function safeCategory(string $category): string
    {
        return match ($category) {
            'network_failure', 'http_status', 'peer_address_mismatch' => 'network_failure',
            'unsupported_content', 'unsupported_content_encoding' => 'unsupported_content',
            default => 'budget_exceeded',
        };
    }

    private function cancel(AnalysisJob $job): void
    {
        $job->observations()->delete();
        $job->pageFetches()->delete();
        $job->report()->delete();
        $this->failSafely($job, AnalysisState::Cancelled, null);
    }

    private function failSafely(AnalysisJob $job, AnalysisState $state, ?string $category): void
    {
        $job->forceFill([
            'state' => $state,
            'safe_failure_category' => $category,
            'completed_at' => now(),
            'extraction' => null,
            'provenance' => null,
        ])->save();
    }
}
