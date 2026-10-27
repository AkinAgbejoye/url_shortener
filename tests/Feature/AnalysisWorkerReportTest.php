<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Jobs\AcquireSinglePage;
use App\Jobs\DeleteAnalysis;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisReportBuilder;
use App\Services\SafePageExtractor;
use App\Services\SinglePageAcquirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AnalysisWorkerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_builds_typed_report_and_completes_the_lifecycle(): void
    {
        $job = $this->job();
        $result = $this->fixtureResult();
        $acquirer = Mockery::mock(SinglePageAcquirer::class);
        $acquirer->shouldReceive('acquire')->once()->andReturnUsing(function () use ($job, $result): array {
            $this->assertSame(AnalysisState::Fetching, $job->refresh()->state);

            return $result;
        });

        (new AcquireSinglePage($job->id))->handle($acquirer, app(AnalysisReportBuilder::class));

        $job->refresh();
        $this->assertSame(AnalysisState::Completed, $job->state);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->completed_at);
        $this->assertNotNull($job->report);
        $this->assertGreaterThan(0, $job->observations()->count());
    }

    public function test_empty_page_partially_completes_and_generation_failure_is_recoverable(): void
    {
        $emptyJob = $this->job();
        $acquirer = Mockery::mock(SinglePageAcquirer::class);
        $acquirer->shouldReceive('acquire')->once()->andReturn([
            'source_url' => 'https://public.example/',
            'extraction' => ['identity' => [], 'heading_details' => [], 'text' => '', 'links' => []],
            'provenance' => $this->provenance(),
        ]);
        (new AcquireSinglePage($emptyJob->id))->handle($acquirer, app(AnalysisReportBuilder::class));
        $this->assertSame(AnalysisState::PartiallyCompleted, $emptyJob->refresh()->state);
        $this->assertNotNull($emptyJob->report);

        $failedJob = $this->job();
        $failedAcquirer = Mockery::mock(SinglePageAcquirer::class);
        $failedAcquirer->shouldReceive('acquire')->once()->andReturn($this->fixtureResult());
        $builder = Mockery::mock(AnalysisReportBuilder::class);
        $builder->shouldReceive('build')->once()->andThrow(new RuntimeException('secret internal detail'));
        (new AcquireSinglePage($failedJob->id))->handle($failedAcquirer, $builder);

        $failedJob->refresh();
        $this->assertSame(AnalysisState::Failed, $failedJob->state);
        $this->assertSame('internal_failure', $failedJob->safe_failure_category);
        $this->assertNull($failedJob->report);
        $this->assertNull($failedJob->extraction);
    }

    public function test_deletion_removes_the_report_evidence_graph(): void
    {
        $job = $this->job();
        app(AnalysisReportBuilder::class)->build($job, $this->fixtureResult());

        (new DeleteAnalysis($job->id))->handle();

        $this->assertDatabaseMissing('analysis_jobs', ['id' => $job->id]);
        $this->assertDatabaseCount('analysis_reports', 0);
        $this->assertDatabaseCount('analysis_page_fetches', 0);
        $this->assertDatabaseCount('analysis_observations', 0);
    }

    public function test_policy_failure_enters_the_blocked_state_without_evidence(): void
    {
        $job = $this->job();
        $acquirer = Mockery::mock(SinglePageAcquirer::class);
        $acquirer->shouldReceive('acquire')->once()->andThrow(new AcquisitionPolicyViolation('blocked_network'));

        (new AcquireSinglePage($job->id))->handle($acquirer, app(AnalysisReportBuilder::class));

        $job->refresh();
        $this->assertSame(AnalysisState::Blocked, $job->state);
        $this->assertSame('policy_blocked', $job->safe_failure_category);
        $this->assertNull($job->report);
    }

    /** @return array<string, mixed> */
    private function fixtureResult(): array
    {
        $html = file_get_contents(base_path('tests/Fixtures/intelligence/shop-page.html'));
        $this->assertIsString($html);

        return (new SafePageExtractor)->extract($html, 'text/html', null, 'https://public.example/', 200);
    }

    /** @return array<string, mixed> */
    private function provenance(): array
    {
        return [
            'fetched_at' => now()->toISOString(), 'status' => 200, 'content_type' => 'text/html',
            'content_hash' => hash('sha256', ''), 'compressed_bytes' => 0, 'decompressed_bytes' => 0,
        ];
    }

    private function job(): AnalysisJob
    {
        return AnalysisJob::create([
            'owner_id' => User::factory()->create()->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://public.example/',
            'submitted_origin' => 'https://public.example',
            'origin_hash' => hash('sha256', Str::random()),
            'domain_hash' => hash('sha256', Str::random()),
            'state' => AnalysisState::Queued,
            'expires_at' => now()->addDay(),
        ]);
    }
}
