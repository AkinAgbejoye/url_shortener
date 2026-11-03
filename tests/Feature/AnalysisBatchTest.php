<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Jobs\AcquireSinglePage;
use App\Jobs\ProcessAnalysisBatch;
use App\Models\AnalysisBatch;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisBatchService;
use App\Services\AnalysisJobService;
use App\Services\AnalysisRetentionPruner;
use App\Services\ApiKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalysisBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_deterministic_bounded_and_creates_no_work(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $token = $this->key($owner, ['analyses:write']);
        $manifest = "url,authorization_attestation,user_context\nhttps://example.test/a,I am authorized to analyze this page.,context\nhttps://example.test/a,I am authorized to analyze this page.,context\nfile:///etc/passwd,I am authorized to analyze this page.,context";

        $this->withToken($token)->postJson('/api/v1/analysis-batches/preview', ['format' => 'csv', 'manifest' => $manifest])
            ->assertOk()
            ->assertJsonPath('valid_rows', 1)
            ->assertJsonPath('invalid_rows', 2)
            ->assertJsonPath('rows.1.errors.0', 'duplicate_row')
            ->assertJsonPath('rows.2.errors.0', 'invalid_url');

        $this->assertDatabaseCount('analysis_batches', 0);
        $this->assertDatabaseCount('analysis_jobs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_json_batch_is_encrypted_and_queued_for_async_processing(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $token = $this->key($owner, ['analyses:write']);
        $manifest = json_encode([$this->row('https://example.test/one')], JSON_THROW_ON_ERROR);

        $response = $this->withToken($token)->postJson('/api/v1/analysis-batches', ['format' => 'json', 'manifest' => $manifest])
            ->assertAccepted()
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('progress.total', 1)
            ->assertJsonMissingPath('manifest');

        $batch = AnalysisBatch::query()->sole();
        $this->assertSame($response->json('id'), $batch->public_id);
        $this->assertStringNotContainsString('example.test', (string) $this->getConnection()->table('analysis_batches')->value('manifest_rows'));
        Bus::assertDispatched(ProcessAnalysisBatch::class);
    }

    public function test_malformed_oversized_and_over_row_limit_manifests_are_rejected(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $token = $this->key($owner, ['analyses:write']);

        $this->withToken($token)->postJson('/api/v1/analysis-batches/preview', ['format' => 'json', 'manifest' => '{broken'])
            ->assertUnprocessable()->assertJsonPath('error.message', 'invalid_json');
        config(['website_intelligence.bulk.max_manifest_bytes' => 1024]);
        $this->withToken($token)->postJson('/api/v1/analysis-batches/preview', ['format' => 'json', 'manifest' => str_repeat('x', 1025)])
            ->assertUnprocessable()->assertJsonPath('error.message', 'manifest_too_large_or_invalid');

        config(['website_intelligence.bulk.max_manifest_bytes' => 100000, 'website_intelligence.bulk.max_rows' => 1]);
        $this->withToken($token)->postJson('/api/v1/analysis-batches/preview', [
            'format' => 'json', 'manifest' => json_encode([$this->row('https://one.example.test'), $this->row('https://two.example.test')], JSON_THROW_ON_ERROR),
        ])->assertUnprocessable()->assertJsonPath('error.message', 'too_many_rows');
        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    public function test_worker_reports_partial_success_and_retry_is_bounded(): void
    {
        Bus::fake();
        config(['website_intelligence.budgets.max_active_per_account' => 1]);
        $owner = User::factory()->create();
        $batch = app(AnalysisBatchService::class)->create($owner, json_encode([
            $this->row('https://one.example.test/path'),
            $this->row('https://two.example.test/path'),
        ], JSON_THROW_ON_ERROR), 'json');

        (new ProcessAnalysisBatch($batch->id))->handle(app(AnalysisJobService::class));

        $batch->refresh();
        $this->assertSame('partially_completed', $batch->state);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(1, $batch->successful_rows);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertDatabaseHas('analysis_batch_rows', ['analysis_batch_id' => $batch->id, 'row_number' => 2, 'safe_error' => 'quota_or_budget']);
        Bus::assertDispatched(AcquireSinglePage::class, 1);

        $token = $this->key($owner, ['analyses:write']);
        $this->withToken($token)->postJson('/api/v1/analysis-batches/'.$batch->public_id.'/retry')
            ->assertAccepted()->assertJsonPath('progress.processed', 1)->assertJsonPath('progress.failed', 0);
        Bus::assertDispatched(ProcessAnalysisBatch::class, 2);
    }

    public function test_cancellation_prevents_worker_from_creating_rows(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $batch = app(AnalysisBatchService::class)->create($owner, json_encode([$this->row('https://example.test/')], JSON_THROW_ON_ERROR), 'json');
        $token = $this->key($owner, ['analyses:write']);

        $this->withToken($token)->postJson('/api/v1/analysis-batches/'.$batch->public_id.'/cancellation')
            ->assertAccepted()->assertJsonPath('state', 'cancelled');
        (new ProcessAnalysisBatch($batch->id))->handle(app(AnalysisJobService::class));

        $this->assertDatabaseCount('analysis_jobs', 0);
        $this->assertDatabaseCount('analysis_batch_rows', 0);
    }

    public function test_each_row_rechecks_current_account_verification(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $batch = app(AnalysisBatchService::class)->create($owner, json_encode([$this->row('https://example.test/')], JSON_THROW_ON_ERROR), 'json');
        $owner->forceFill(['email_verified_at' => null])->save();

        (new ProcessAnalysisBatch($batch->id))->handle(app(AnalysisJobService::class));

        $this->assertDatabaseCount('analysis_jobs', 0);
        $this->assertDatabaseHas('analysis_batch_rows', ['analysis_batch_id' => $batch->id, 'safe_error' => 'policy_or_validation']);
    }

    public function test_batch_controls_do_not_reveal_foreign_resources(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $foreign = app(AnalysisBatchService::class)->create(User::factory()->create(), json_encode([$this->row('https://foreign.example.test/')], JSON_THROW_ON_ERROR), 'json');
        $token = $this->key($owner, ['analyses:read', 'analyses:write']);

        $foreignResponse = $this->withToken($token)->getJson('/api/v1/analysis-batches/'.$foreign->public_id)->assertNotFound();
        $missingResponse = $this->withToken($token)->getJson('/api/v1/analysis-batches/batch_'.Str::ulid())->assertNotFound();
        $this->assertSame($foreignResponse->json('error.code'), $missingResponse->json('error.code'));
        $this->assertSame($foreignResponse->json('error.message'), $missingResponse->json('error.message'));
        $this->withToken($token)->deleteJson('/api/v1/analysis-batches/'.$foreign->public_id)->assertNotFound();
    }

    public function test_streaming_exports_are_owner_scoped_and_formula_safe(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->analysis($owner, '=spreadsheet-formula');
        $foreign = $this->analysis($other, 'https://foreign.example');
        $token = $this->key($owner, ['analyses:read']);

        $csv = $this->withToken($token)->postJson('/api/v1/analysis-batches/exports', [
            'format' => 'csv', 'analysis_ids' => [$owned->public_id, $foreign->public_id],
        ])->assertOk();
        $this->assertStringContainsString('no-store', (string) $csv->headers->get('Cache-Control'));
        $content = $csv->streamedContent();
        $this->assertStringContainsString("'=spreadsheet-formula", $content);
        $this->assertStringNotContainsString($foreign->public_id, $content);

        $json = $this->withToken($token)->postJson('/api/v1/analysis-batches/exports', [
            'format' => 'json', 'analysis_ids' => [$owned->public_id, $foreign->public_id],
        ])->streamedContent();
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $decoded);
        $this->assertSame($owned->public_id, $decoded[0]['analysis_id']);
        $this->assertArrayNotHasKey('target_url', $decoded[0]);
    }

    public function test_verified_account_page_exposes_accessible_preview_and_controls(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/account/analysis-batches')
            ->assertOk()
            ->assertSee('Bulk analysis import and report export')
            ->assertSee('Preview only')
            ->assertSee('Skip to bulk analysis controls');
        $this->actingAs(User::factory()->unverified()->create())->get('/account/analysis-batches')->assertRedirect('/verify-email');
    }

    public function test_expired_batch_metadata_and_encrypted_manifest_are_pruned(): void
    {
        Bus::fake();
        $batch = app(AnalysisBatchService::class)->create(User::factory()->create(), json_encode([$this->row('https://example.test/')], JSON_THROW_ON_ERROR), 'json');
        $batch->forceFill(['expires_at' => now()->subSecond()])->save();

        app(AnalysisRetentionPruner::class)->prune(CarbonImmutable::now('UTC'), 7, 100, false);

        $this->assertDatabaseMissing('analysis_batches', ['id' => $batch->id]);
    }

    /** @return array<string, mixed> */
    private function row(string $url): array
    {
        return ['url' => $url, 'authorization_attestation' => 'I am authorized to analyze this public page.', 'user_context' => ['Public page']];
    }

    /** @param list<string> $scopes */
    private function key(User $owner, array $scopes): string
    {
        return app(ApiKeyService::class)->create($owner, 'batch test', $scopes, null)['plain_text_key'];
    }

    private function analysis(User $owner, string $origin): AnalysisJob
    {
        return AnalysisJob::create([
            'owner_id' => $owner->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://secret.example/path',
            'submitted_origin' => $origin,
            'origin_hash' => hash('sha256', Str::random()),
            'domain_hash' => hash('sha256', Str::random()),
            'state' => AnalysisState::Completed,
            'expires_at' => now()->addDay(),
        ]);
    }
}
