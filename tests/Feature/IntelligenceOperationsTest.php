<?php

namespace Tests\Feature;

use App\Contracts\IntelligenceDependencyProbe;
use App\Models\User;
use App\Services\IntelligenceReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class IntelligenceOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_is_constant_and_does_not_resolve_downstream_dependencies(): void
    {
        $this->app->instance(IntelligenceDependencyProbe::class, new class implements IntelligenceDependencyProbe
        {
            public function check(): array
            {
                throw new RuntimeException('Sensitive dependency failure');
            }
        });

        $this->getJson('/api/intelligence/liveness')
            ->assertOk()
            ->assertExactJson(['status' => 'alive']);
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_has_constant_healthy_degraded_and_timeout_shapes(): void
    {
        config([
            'website_intelligence.readiness.cache_seconds' => 0,
            'website_intelligence.readiness.check_timeout_ms' => 50,
        ]);

        $this->probe(['worker' => ['status' => 'disabled', 'duration_ms' => 0.0]]);
        $this->getJson('/api/intelligence/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonCount(6, 'checks')
            ->assertJsonPath('checks.worker', 'disabled');

        $this->probe(['storage' => ['status' => 'not_ready', 'duration_ms' => 2.0]]);
        $failed = $this->getJson('/api/intelligence/readiness')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.storage', 'not_ready');

        $this->probe(['database' => ['status' => 'ready', 'duration_ms' => 51.0]]);
        $timedOut = $this->getJson('/api/intelligence/readiness')
            ->assertServiceUnavailable()
            ->assertJsonPath('checks.database', 'not_ready');

        foreach ([$failed->getContent(), $timedOut->getContent()] as $json) {
            foreach (['exception', 'hostname', 'database.sqlite', 'password', 'owner'] as $private) {
                $this->assertStringNotContainsStringIgnoringCase($private, $json);
            }
        }
    }

    public function test_operator_dashboard_is_allowlisted_verified_accessible_and_private(): void
    {
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $unverified = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
        config(['website_intelligence.operators.emails' => ['operator@example.com', 'unverified@example.com']]);
        $this->probe(['worker' => ['status' => 'disabled', 'duration_ms' => 0.0]]);

        Cache::put(config('website_intelligence.operations.snapshot_key'), [
            'generated_at' => now()->subHour()->toIso8601String(),
            'queue' => ['queued_jobs' => 7, 'active_jobs' => -4, 'worker_capacity' => 2],
            'outcomes' => ['failed' => 3],
            'destination_url' => 'https://private.example/secret?token=leak',
            'owner_id' => $operator->id,
            'exception' => 'database.internal',
        ], 300);

        $this->get('/account/operations/intelligence')->assertRedirect('/login');
        $this->actingAs($other)->get('/account/operations/intelligence')->assertNotFound();
        $this->actingAs($unverified)->get('/account/operations/intelligence')->assertNotFound();

        $page = $this->actingAs($operator)->get('/account/operations/intelligence')
            ->assertOk()
            ->assertSee('Website intelligence operations')
            ->assertSee('role="status"', false)
            ->assertSee('role="alert"', false)
            ->assertSee('<caption', false)
            ->assertSee('Aggregate operations signal is stale')
            ->assertSee('queued jobs')
            ->assertSee('7');

        foreach (['private.example', 'token=leak', 'owner_id', 'database.internal'] as $private) {
            $page->assertDontSee($private, false);
        }

        $json = $this->actingAs($operator)->getJson('/account/operations/intelligence/data')
            ->assertOk()
            ->assertJsonPath('operations.signal', 'stale')
            ->assertJsonPath('operations.queue.queued_jobs', 7)
            ->assertJsonPath('operations.queue.active_jobs', 0)
            ->assertJsonMissingPath('operations.destination_url')
            ->getContent();
        $this->assertStringNotContainsString('private.example', $json);
        $this->assertStringNotContainsString('owner_id', $json);
    }

    /** @param array<string, array{status: string, duration_ms: float}> $overrides */
    private function probe(array $overrides): void
    {
        $ready = [];
        foreach (['database', 'queue', 'cache', 'lock', 'storage', 'worker'] as $name) {
            $ready[$name] = ['status' => 'ready', 'duration_ms' => 1.0];
        }

        $this->app->instance(IntelligenceDependencyProbe::class, new class([...$ready, ...$overrides]) implements IntelligenceDependencyProbe
        {
            public function __construct(private readonly array $results) {}

            public function check(): array
            {
                return $this->results;
            }
        });
        $this->app->forgetInstance(IntelligenceReadinessService::class);
    }
}
