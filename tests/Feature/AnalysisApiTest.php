<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Jobs\AcquireSinglePage;
use App\Jobs\DeleteAnalysis;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisReportBuilder;
use App\Services\ApiKeyService;
use App\Services\SinglePageAcquirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_scoped_account_can_create_and_replay_an_analysis(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $token = $this->key($owner, ['analyses:read', 'analyses:write']);
        $payload = [
            'url' => 'https://Public.Example/path#fragment',
            'authorization_attestation' => 'I am authorized to analyze this public page.',
            'user_context' => ['Public marketing page'],
        ];
        $headers = ['Idempotency-Key' => 'analysis-request-0001'];

        $created = $this->withToken($token)->postJson('/api/v1/analyses', $payload, $headers)
            ->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('submitted_origin', 'https://public.example')
            ->assertJsonMissingPath('target_url');
        $replayed = $this->withToken($token)->postJson('/api/v1/analyses', $payload, $headers)
            ->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($created->json('id'), $replayed->json('id'));
        $this->assertDatabaseCount('analysis_jobs', 1);
        Bus::assertDispatchedTimes(AcquireSinglePage::class, 1);
    }

    public function test_idempotency_conflicts_quotas_and_unverified_accounts_are_bounded(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $token = $this->key($owner, ['analyses:write']);
        $headers = ['Idempotency-Key' => 'analysis-request-0002'];
        $payload = ['url' => 'https://one.example/', 'authorization_attestation' => str_repeat('a', 20)];

        $this->withToken($token)->postJson('/api/v1/analyses', $payload, $headers)->assertAccepted();
        $this->withToken($token)->postJson('/api/v1/analyses', [...$payload, 'url' => 'https://two.example/'], $headers)
            ->assertConflict()
            ->assertJsonPath('error.code', 'conflict');

        config(['website_intelligence.budgets.max_active_per_account' => 1]);
        $this->withToken($token)->postJson('/api/v1/analyses', [...$payload, 'url' => 'https://three.example/'], ['Idempotency-Key' => 'analysis-request-0003'])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited');

        $unverified = User::factory()->unverified()->create();
        $this->withToken($this->key($unverified, ['analyses:write']))
            ->postJson('/api/v1/analyses', $payload, ['Idempotency-Key' => 'analysis-request-0004'])
            ->assertForbidden();
    }

    public function test_owner_isolation_has_the_same_missing_and_foreign_shape(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->key($owner, ['analyses:read']);
        $foreign = $this->analysis($other);

        $foreignResponse = $this->withToken($token)->getJson('/api/v1/analyses/'.$foreign->public_id)->assertNotFound();
        $missingResponse = $this->withToken($token)->getJson('/api/v1/analyses/ana_'.Str::random(26))->assertNotFound();

        $this->assertSame($foreignResponse->json('error.code'), $missingResponse->json('error.code'));
        $this->assertSame($foreignResponse->json('error.message'), $missingResponse->json('error.message'));
        $this->assertSame(strlen($foreignResponse->getContent()), strlen($missingResponse->getContent()));
    }

    public function test_listing_cancellation_and_deletion_remain_owner_scoped(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->key($owner, ['analyses:read', 'analyses:write']);
        $owned = $this->analysis($owner);
        $foreign = $this->analysis($other);

        $this->withToken($token)->getJson('/api/v1/analyses')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->public_id)
            ->assertDontSee($foreign->public_id, false);
        $this->withToken($token)->postJson('/api/v1/analyses/'.$owned->public_id.'/cancellation')
            ->assertAccepted()->assertJsonPath('state', 'cancel_requested');
        $this->withToken($token)->deleteJson('/api/v1/analyses/'.$owned->public_id)->assertAccepted();
        Bus::assertDispatched(DeleteAnalysis::class);
        $this->withToken($token)->deleteJson('/api/v1/analyses/'.$foreign->public_id)->assertNotFound();
    }

    public function test_analysis_scopes_do_not_grant_other_capabilities(): void
    {
        $owner = User::factory()->create();
        $read = $this->key($owner, ['analyses:read']);
        $write = $this->key($owner, ['analyses:write']);

        $this->withToken($read)->getJson('/api/v1/analyses')->assertOk();
        $this->withToken($read)->postJson('/api/v1/analyses', [])->assertForbidden();
        $this->withToken($write)->getJson('/api/v1/analyses')->assertForbidden();
        $this->withToken($write)->getJson('/api/v1/urls')->assertForbidden();
    }

    public function test_worker_honors_cancellation_before_any_network_work(): void
    {
        $job = $this->analysis(User::factory()->create());
        $job->forceFill(['state' => AnalysisState::CancelRequested, 'cancel_requested_at' => now()])->save();

        (new AcquireSinglePage($job->id))->handle(app(SinglePageAcquirer::class), app(AnalysisReportBuilder::class));

        $this->assertSame(AnalysisState::Cancelled, $job->refresh()->state);
        $this->assertNotNull($job->completed_at);
        $this->assertNull($job->safe_failure_category);
    }

    /** @param list<string> $scopes */
    private function key(User $owner, array $scopes): string
    {
        return app(ApiKeyService::class)->create($owner, 'analysis test', $scopes, null)['plain_text_key'];
    }

    private function analysis(User $owner): AnalysisJob
    {
        return AnalysisJob::create([
            'owner_id' => $owner->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://secret.example/path',
            'submitted_origin' => 'https://secret.example',
            'origin_hash' => hash('sha256', Str::random()),
            'domain_hash' => hash('sha256', Str::random()),
            'state' => AnalysisState::Queued,
            'expires_at' => now()->addDay(),
        ]);
    }
}
