<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Services\UrlCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class UrlManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_returns_the_token_once_and_persists_only_its_hash(): void
    {
        $headers = ['Idempotency-Key' => 'managed-create'];
        $created = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://managed.example',
        ], $headers)->assertCreated();
        $token = $created->headers->get('X-Management-Token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertArrayNotHasKey('management_token', $created->json());

        $url = Url::firstOrFail();
        $this->assertSame(hash('sha256', $token), $url->management_token_hash);
        $this->assertStringNotContainsString($token, json_encode($url->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(
            $token,
            json_encode(IdempotencyKey::firstOrFail()->response, JSON_THROW_ON_ERROR),
        );

        $replay = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://managed.example',
        ], $headers)->assertOk();
        $this->assertNull($replay->headers->get('X-Management-Token'));
        $this->assertSame($created->json(), $replay->json());
    }

    public function test_missing_invalid_and_unknown_credentials_share_the_same_not_found_response(): void
    {
        [$shortCode] = $this->createManagedUrl();

        $responses = [
            $this->getJson("/api/v1/urls/{$shortCode}"),
            $this->withHeader('X-Management-Token', str_repeat('0', 64))
                ->getJson("/api/v1/urls/{$shortCode}"),
            $this->withHeader('X-Management-Token', str_repeat('a', 64))
                ->getJson('/api/v1/urls/unknown'),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
        }

        $this->assertSame($responses[0]->json('message'), $responses[1]->json('message'));
        $this->assertSame($responses[1]->json('message'), $responses[2]->json('message'));
        foreach ($responses as $response) {
            $this->assertStringNotContainsString($shortCode, $response->json('message'));
        }
    }

    public function test_the_token_can_inspect_and_update_expiration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T09:00:00Z'));
        [$shortCode, $token] = $this->createManagedUrl();
        Cache::put("url:{$shortCode}", ['stale' => true], now()->addHour());

        $this->asManager($token)->getJson("/api/v1/urls/{$shortCode}")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('expires_at', null);

        $this->asManager($token)->patchJson("/api/v1/urls/{$shortCode}", [
            'expires_at' => '2026-09-28T11:00:00+02:00',
        ])->assertOk()
            ->assertJsonPath('expires_at', '2026-09-28T09:00:00+00:00');

        $this->assertNull(Cache::get("url:{$shortCode}"));
        $this->assertSame(
            '2026-09-28T09:00:00+00:00',
            Url::where('short_code', $shortCode)->firstOrFail()->expires_at->toIso8601String(),
        );

        $this->asManager($token)->patchJson("/api/v1/urls/{$shortCode}", [
            'expires_at' => null,
        ])->assertOk()->assertJsonPath('expires_at', null);
    }

    public function test_disable_and_enable_are_idempotent_and_invalidate_redirect_cache(): void
    {
        [$shortCode, $token] = $this->createManagedUrl();
        $this->get("/{$shortCode}")->assertRedirect('https://managed.example');

        $this->asManager($token)->postJson("/api/v1/urls/{$shortCode}/disable")
            ->assertOk()->assertJsonPath('status', 'disabled');
        $this->assertNull(Cache::get("url:{$shortCode}"));
        $this->get("/{$shortCode}")->assertNotFound();

        $this->asManager($token)->postJson("/api/v1/urls/{$shortCode}/disable")
            ->assertOk()->assertJsonPath('status', 'disabled');
        $this->asManager($token)->postJson("/api/v1/urls/{$shortCode}/enable")
            ->assertOk()->assertJsonPath('status', 'active');
        $this->get("/{$shortCode}")->assertRedirect('https://managed.example');
    }

    public function test_delete_is_final_for_the_anonymous_management_api(): void
    {
        [$shortCode, $token] = $this->createManagedUrl();

        $this->asManager($token)->deleteJson("/api/v1/urls/{$shortCode}")->assertNoContent();

        $this->assertSoftDeleted('urls', ['short_code' => $shortCode]);
        $this->get("/{$shortCode}")->assertNotFound();
        $this->asManager($token)->getJson("/api/v1/urls/{$shortCode}")->assertNotFound();
        $this->asManager($token)->postJson("/api/v1/urls/{$shortCode}/enable")->assertNotFound();
    }

    public function test_expiration_updates_keep_the_validation_contract(): void
    {
        [$shortCode, $token] = $this->createManagedUrl();

        $this->asManager($token)->patchJson("/api/v1/urls/{$shortCode}", [])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->asManager($token)->patchJson("/api/v1/urls/{$shortCode}", [
            'expires_at' => 'not-a-timestamp',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'errors.expires_at.0',
                'The expiration must be a valid ISO-8601 timestamp with a timezone.',
            );
    }

    public function test_management_requests_are_rate_limited(): void
    {
        [$shortCode, $token] = $this->createManagedUrl();
        Cache::clear();

        foreach (range(1, 30) as $attempt) {
            $this->asManager($token)->getJson("/api/v1/urls/{$shortCode}")->assertOk();
        }

        $this->asManager($token)->getJson("/api/v1/urls/{$shortCode}")->assertTooManyRequests();
    }

    public function test_a_cache_invalidation_failure_rolls_back_the_mutation(): void
    {
        [$shortCode, $token] = $this->createManagedUrl();
        $cache = \Mockery::mock(UrlCache::class);
        $cache->shouldReceive('forget')->once()->with($shortCode)
            ->andThrow(new RuntimeException('Cache unavailable'));
        $this->app->instance(UrlCache::class, $cache);

        $this->asManager($token)->postJson("/api/v1/urls/{$shortCode}/disable")
            ->assertInternalServerError();

        $this->assertNull(Url::where('short_code', $shortCode)->firstOrFail()->disabled_at);
    }

    /** @return array{string, string} */
    private function createManagedUrl(): array
    {
        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://managed.example',
        ])->assertCreated();

        return [$response->json('short_code'), $response->headers->get('X-Management-Token')];
    }

    private function asManager(string $token): static
    {
        return $this->withHeader('X-Management-Token', $token);
    }
}
