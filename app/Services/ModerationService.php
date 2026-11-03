<?php

namespace App\Services;

use App\Enums\AnalysisState;
use App\Models\AbuseReport;
use App\Models\AnalysisJob;
use App\Models\ModerationAuditEvent;
use App\Models\ModerationDomainBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ModerationService
{
    /** @return array{report: AbuseReport, acknowledged: true} */
    public function report(?User $reporter, array $input): array
    {
        $reference = is_string($input['subject_reference'] ?? null) ? trim($input['subject_reference']) : '';
        $report = AbuseReport::create([
            'public_id' => 'abr_'.Str::ulid(),
            'reporter_id' => $reporter?->getKey(),
            'category' => $input['category'],
            'reporter_email' => ($input['contact_consent'] ?? false) ? ($input['reporter_email'] ?? $reporter?->email) : null,
            'contact_consent' => (bool) ($input['contact_consent'] ?? false),
            'description' => $input['description'],
            'evidence_reference' => $input['evidence_reference'] ?? null,
            'subject_reference_hash' => $reference === '' ? null : hash_hmac('sha256', $reference, (string) config('app.key')),
            'status' => 'received',
            'retained_until' => now()->addDays(max(1, (int) config('website_intelligence.moderation.retention_days', 90))),
        ]);

        return ['report' => $report, 'acknowledged' => true];
    }

    public function act(User $operator, AbuseReport $report, string $action, ?AnalysisJob $job = null): void
    {
        DB::transaction(function () use ($operator, $report, $action, $job): void {
            match ($action) {
                'assign' => $report->forceFill(['assigned_operator_id' => $operator->id, 'status' => 'reviewing'])->save(),
                'resolve' => $report->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save(),
                'accept_appeal' => $report->forceFill(['status' => 'appeal_accepted', 'resolved_at' => now()])->save(),
                'cancel_job' => $this->cancelJob($job),
                'pause_job' => $this->cancelJob($job),
                'block_domain' => $this->blockDomain($operator, $job),
                'suspend_account' => $this->suspendAccount($job),
                'resume_account' => $this->resumeAccount($job),
                'release_domain' => $this->releaseDomain($job),
                'delete_report_data' => $report->forceFill([
                    'reporter_email' => null, 'description' => '[deleted]', 'evidence_reference' => null,
                    'subject_reference_hash' => null, 'status' => 'deleted', 'resolved_at' => now(), 'retained_until' => now(),
                ])->save(),
                default => throw new InvalidArgumentException('Unsupported moderation action.'),
            };

            ModerationAuditEvent::create([
                'public_id' => 'mod_'.Str::ulid(), 'actor_id' => $operator->id, 'action' => $action,
                'subject_type' => $job === null ? 'abuse_report' : 'analysis_job',
                'subject_hash' => hash_hmac('sha256', $job?->public_id ?? $report->public_id, (string) config('app.key')),
                'safe_metadata' => ['category' => $report->category], 'occurred_at' => now(),
            ]);
        }, 3);
    }

    private function cancelJob(?AnalysisJob $job): void
    {
        $job = $this->requiredJob($job);
        if ($job->state->active()) {
            $job->forceFill(['state' => AnalysisState::CancelRequested, 'cancel_requested_at' => now()])->save();
        }
    }

    private function blockDomain(User $operator, ?AnalysisJob $job): void
    {
        $job = $this->requiredJob($job);
        ModerationDomainBlock::query()->updateOrCreate(
            ['domain_hash' => $job->domain_hash],
            ['category' => 'reported_abuse', 'created_by' => $operator->id, 'blocked_at' => now(), 'released_at' => null],
        );
        AnalysisJob::query()->where('domain_hash', $job->domain_hash)
            ->whereIn('state', array_map(fn (AnalysisState $state): string => $state->value, array_filter(AnalysisState::cases(), fn (AnalysisState $state): bool => $state->active())))
            ->update(['state' => AnalysisState::CancelRequested->value, 'cancel_requested_at' => now(), 'updated_at' => now()]);
    }

    private function suspendAccount(?AnalysisJob $job): void
    {
        $job = $this->requiredJob($job);
        $job->owner()->update(['intelligence_suspended_at' => now()]);
        AnalysisJob::query()->where('owner_id', $job->owner_id)
            ->whereIn('state', array_map(fn (AnalysisState $state): string => $state->value, array_filter(AnalysisState::cases(), fn (AnalysisState $state): bool => $state->active())))
            ->update(['state' => AnalysisState::CancelRequested->value, 'cancel_requested_at' => now(), 'updated_at' => now()]);
    }

    private function releaseDomain(?AnalysisJob $job): void
    {
        $job = $this->requiredJob($job);
        ModerationDomainBlock::query()->where('domain_hash', $job->domain_hash)->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);
    }

    private function resumeAccount(?AnalysisJob $job): void
    {
        $this->requiredJob($job)->owner()->update(['intelligence_suspended_at' => null]);
    }

    private function requiredJob(?AnalysisJob $job): AnalysisJob
    {
        return $job ?? throw new InvalidArgumentException('This action requires an analysis job.');
    }
}
