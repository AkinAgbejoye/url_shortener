<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Models\AnalysisJob;
use App\Models\AnalysisObservation;
use App\Models\AnalysisPageFetch;
use App\Models\AnalysisReport;
use App\Models\User;
use App\Services\AnalysisRetentionPruner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalysisRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_clears_evidence_then_metadata_is_deleted_after_the_grace_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
        $fresh = $this->reportJob(now()->addSecond());
        $boundary = $this->reportJob(now());
        $old = $this->reportJob(now()->subDays(7));
        $old->forceFill(['state' => AnalysisState::Expired, 'extraction' => null, 'provenance' => null])->save();
        $pruner = app(AnalysisRetentionPruner::class);

        $counts = $pruner->prune(CarbonImmutable::now('UTC'), 7, 100, false);

        $this->assertSame(['examined' => 2, 'expired' => 1, 'deleted' => 1, 'failed' => 0], $counts);
        $this->assertSame(AnalysisState::Expired, $boundary->refresh()->state);
        $this->assertNull($boundary->extraction);
        $this->assertDatabaseMissing('analysis_reports', ['analysis_job_id' => $boundary->id]);
        $this->assertDatabaseMissing('analysis_page_fetches', ['analysis_job_id' => $boundary->id]);
        $this->assertDatabaseMissing('analysis_jobs', ['id' => $old->id]);
        $this->assertDatabaseHas('analysis_jobs', ['id' => $fresh->id]);
    }

    public function test_dry_run_and_command_validation_do_not_mutate_retained_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
        $job = $this->reportJob(now()->subSecond());

        $counts = app(AnalysisRetentionPruner::class)->prune(CarbonImmutable::now('UTC'), 7, 10, true);

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(AnalysisState::Completed, $job->refresh()->state);
        $this->assertDatabaseHas('analysis_reports', ['analysis_job_id' => $job->id]);
        $this->artisan('intelligence:prune-retention', ['--batch-size' => 0])->assertFailed();
        $this->artisan('intelligence:prune-retention', ['--dry-run' => true])->assertSuccessful();
    }

    private function reportJob($expiresAt): AnalysisJob
    {
        $job = AnalysisJob::create([
            'owner_id' => User::factory()->create()->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://public.example/',
            'submitted_origin' => 'https://public.example',
            'origin_hash' => hash('sha256', Str::random()),
            'domain_hash' => hash('sha256', Str::random()),
            'state' => AnalysisState::Completed,
            'extraction' => ['text' => 'retained'],
            'provenance' => ['content_hash' => hash('sha256', 'retained')],
            'expires_at' => $expiresAt,
        ]);
        $fetch = AnalysisPageFetch::create([
            'analysis_job_id' => $job->id,
            'public_id' => 'fetch_'.Str::ulid(),
            'source_url' => 'https://public.example/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'content_hash' => hash('sha256', 'retained'),
            'compressed_bytes' => 8,
            'decompressed_bytes' => 8,
            'fetched_at' => now(),
        ]);
        AnalysisReport::create([
            'analysis_job_id' => $job->id,
            'public_id' => 'rpt_'.Str::ulid(),
            'schema_version' => '1.0',
            'generated_at' => now(),
            'expires_at' => $expiresAt,
        ]);
        AnalysisObservation::create([
            'analysis_job_id' => $job->id,
            'page_fetch_id' => $fetch->id,
            'public_id' => 'obs_'.Str::ulid(),
            'evidence_public_id' => 'ev_'.Str::ulid(),
            'kind' => 'page_identity',
            'source_location' => 'title',
            'content_hash' => hash('sha256', 'retained'),
            'statement' => 'Page title: retained',
            'excerpt' => 'retained',
            'observed_at' => now(),
        ]);

        return $job;
    }
}
