<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Models\ApiKey;
use App\Models\Url;
use App\Models\User;
use App\Services\ApiKeyAuthenticator;
use App\Services\ApiKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class ApiKeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_scope_allows_only_its_documented_owner_operations(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = $this->url($owner, 'owned', 'https://owner.example/private');
        $foreign = $this->url($other, 'foreign', 'https://other.example/secret');

        [, $readKey] = $this->key($owner, ['urls:read']);
        $this->withToken($readKey)->getJson('/api/v1/urls')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.short_code', $owned->short_code)
            ->assertDontSee($foreign->long_url, false);
        $this->withToken($readKey)->getJson('/api/v1/urls/owned')->assertOk();
        $this->withToken($readKey)->getJson('/api/v1/urls/foreign')->assertNotFound();
        $this->withToken($readKey)->postJson('/api/v1/urls', [
            'long_url' => 'https://blocked.example',
        ])->assertForbidden();
        $this->withToken($readKey)->getJson('/api/v1/urls/owned/analytics')->assertForbidden();

        [, $writeKey] = $this->key($owner, ['urls:write']);
        $created = $this->withToken($writeKey)->postJson('/api/v1/urls', [
            'long_url' => 'https://created.example',
        ])->assertCreated();
        $this->assertNull($created->headers->get('X-Management-Token'));
        $this->assertDatabaseHas('urls', [
            'short_code' => $created->json('short_code'),
            'owner_id' => $owner->id,
        ]);
        $this->withToken($writeKey)->postJson('/api/v1/urls/owned/disable')->assertOk();
        $this->withToken($writeKey)->postJson('/api/v1/urls/foreign/disable')->assertNotFound();
        $this->withToken($writeKey)->getJson('/api/v1/urls/owned')->assertForbidden();

        $this->withoutToken();
        $anonymous = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://claimable.example',
        ])->assertCreated();
        $this->withToken($writeKey)
            ->withHeader('X-Management-Token', $anonymous->headers->get('X-Management-Token'))
            ->postJson('/api/v1/urls/'.$anonymous->json('short_code').'/claim')
            ->assertOk();
        $this->assertDatabaseHas('urls', [
            'short_code' => $anonymous->json('short_code'),
            'owner_id' => $owner->id,
        ]);

        [, $analyticsKey] = $this->key($owner, ['analytics:read']);
        $this->withToken($analyticsKey)->getJson('/api/v1/urls/owned/analytics')->assertOk();
        $this->withToken($analyticsKey)->getJson('/api/v1/urls/foreign/analytics')->assertNotFound();
        $this->withToken($analyticsKey)->getJson('/api/v1/urls/owned')->assertForbidden();
        $this->withToken($analyticsKey)->deleteJson('/api/v1/urls/owned')->assertForbidden();
    }

    public function test_management_tokens_remain_independent_and_owned_credentials_take_precedence(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [, $readKey] = $this->key($owner, ['urls:read']);

        $anonymous = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://anonymous.example',
        ])->assertCreated();
        $managementToken = $anonymous->headers->get('X-Management-Token');
        $shortCode = $anonymous->json('short_code');

        $this->withToken($readKey)
            ->withHeader('X-Management-Token', $managementToken)
            ->getJson("/api/v1/urls/{$shortCode}")
            ->assertOk();

        $foreign = $this->url($other, 'conflict', 'https://foreign.example');
        $conflictingToken = bin2hex(random_bytes(32));
        $foreign->update(['management_token_hash' => hash('sha256', $conflictingToken)]);

        $this->withToken($readKey)
            ->withHeader('X-Management-Token', $conflictingToken)
            ->getJson('/api/v1/urls/conflict')
            ->assertNotFound();
    }

    public function test_malformed_unknown_invalid_expired_and_revoked_keys_share_one_401_contract(): void
    {
        $user = User::factory()->create();
        [$active, $plainTextKey] = $this->key($user, ['urls:read']);
        [, $expiredKey] = $this->key($user, ['urls:read'], now()->subMinute()->toIso8601String());
        [$revoked, $revokedKey] = $this->key($user, ['urls:read']);
        $revoked->update(['revoked_at' => now()]);

        $headers = [
            'Basic '.base64_encode($plainTextKey),
            'Bearer ak_'.str_repeat('z', 20).'.'.str_repeat('Z', 64),
            'Bearer '.$active->public_id.'.'.str_repeat('x', 64),
            'Bearer '.$expiredKey,
            'Bearer '.$revokedKey,
        ];

        foreach ($headers as $authorization) {
            $this->withHeader('Authorization', $authorization)
                ->getJson('/api/v1/urls')
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_revocation_and_expiration_apply_on_the_next_request(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));
        $user = User::factory()->create();
        [$apiKey, $plainTextKey] = $this->key(
            $user,
            ['urls:read'],
            now()->addMinute()->toIso8601String(),
        );

        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $apiKey->update(['revoked_at' => now()]);
        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertUnauthorized();

        [, $expiringKey] = $this->key(
            $user,
            ['urls:read'],
            now()->addSecond()->toIso8601String(),
        );
        $this->withToken($expiringKey)->getJson('/api/v1/urls')->assertOk();
        $this->travel(2)->seconds();
        $this->withToken($expiringKey)->getJson('/api/v1/urls')->assertUnauthorized();
    }

    public function test_api_keys_are_ignored_outside_the_authorization_header(): void
    {
        $user = User::factory()->create();
        [, $plainTextKey] = $this->key($user, ['urls:read', 'urls:write']);

        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $this->withoutToken();
        $this->getJson('/api/v1/urls?api_key='.urlencode($plainTextKey))
            ->assertUnauthorized();

        $body = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://body.example',
            'api_key' => $plainTextKey,
        ])->assertCreated();
        $cookie = $this->withCookie('api_key', $plainTextKey)->postJson('/api/v1/urls', [
            'long_url' => 'https://cookie.example',
        ])->assertCreated();

        $this->assertNotNull($body->headers->get('X-Management-Token'));
        $this->assertNotNull($cookie->headers->get('X-Management-Token'));
        $this->assertDatabaseCount('urls', 2);
        $this->assertSame(0, Url::query()->owned($user)->count());
    }

    public function test_last_used_writes_are_bounded_by_the_configured_interval(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));
        config(['url_shortener.api_keys.last_used_update_seconds' => 300]);
        $user = User::factory()->create();
        [$apiKey, $plainTextKey] = $this->key($user, ['urls:read']);

        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $firstUsedAt = $apiKey->refresh()->last_used_at;
        $this->assertTrue($firstUsedAt->equalTo(now()));

        $this->travel(299)->seconds();
        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $this->assertTrue($apiKey->refresh()->last_used_at->equalTo($firstUsedAt));

        $this->travel(1)->second();
        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $this->assertTrue($apiKey->refresh()->last_used_at->equalTo(now()));
    }

    public function test_rate_limits_use_key_account_and_invalid_credential_identities(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        [, $firstKey] = $this->key($user, ['urls:read']);
        [, $secondKey] = $this->key($user, ['urls:read']);

        config([
            'url_shortener.api_keys.requests_per_minute' => 2,
            'url_shortener.api_keys.account_requests_per_minute' => 100,
        ]);
        $this->withToken($firstKey)->getJson('/api/v1/urls')->assertOk();
        $this->withToken($firstKey)->getJson('/api/v1/urls')->assertOk();
        $this->withToken($firstKey)->getJson('/api/v1/urls')->assertTooManyRequests();
        $this->withToken($secondKey)->getJson('/api/v1/urls')->assertOk();

        Cache::flush();
        config([
            'url_shortener.api_keys.requests_per_minute' => 10,
            'url_shortener.api_keys.account_requests_per_minute' => 2,
        ]);
        $this->withToken($firstKey)->getJson('/api/v1/urls')->assertOk();
        $this->withToken($secondKey)->getJson('/api/v1/urls')->assertOk();
        $this->withToken($secondKey)->getJson('/api/v1/urls')->assertTooManyRequests();

        Cache::flush();
        config(['url_shortener.api_keys.invalid_requests_per_minute' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeader('Authorization', 'Bearer malformed')
            ->getJson('/api/v1/urls')
            ->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeader('Authorization', 'Bearer still-malformed')
            ->getJson('/api/v1/urls')
            ->assertTooManyRequests();
    }

    public function test_authentication_telemetry_is_bounded_private_and_failure_isolated(): void
    {
        $metrics = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $metrics);
        Log::spy();
        $user = User::factory()->create();
        [$apiKey, $plainTextKey] = $this->key($user, ['urls:read']);

        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
        $this->withHeader('Authorization', 'Bearer malformed')
            ->getJson('/api/v1/urls')
            ->assertUnauthorized();

        foreach (['valid', 'malformed'] as $outcome) {
            $this->assertTrue($metrics->hasCounter('api_key_authentication_total', [
                'outcome' => $outcome,
            ]));
        }
        foreach ($metrics->counters as $metric) {
            if ($metric['name'] === 'api_key_authentication_total') {
                $this->assertSame(['outcome'], array_keys($metric['labels']));
                $encoded = json_encode($metric, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString($apiKey->public_id, $encoded);
                $this->assertStringNotContainsString($plainTextKey, $encoded);
                $this->assertStringNotContainsString((string) $user->id, $encoded);
            }
        }
        Log::shouldHaveReceived('info')->with('api_key_authentication', \Mockery::on(
            fn (array $context): bool => array_keys($context) === ['outcome'],
        ));

        $this->app->instance(MetricsExporter::class, new class implements MetricsExporter
        {
            public function increment(string $name, array $labels = []): void
            {
                throw new RuntimeException('Metrics unavailable');
            }

            public function timing(string $name, float $milliseconds, array $labels = []): void
            {
                throw new RuntimeException('Metrics unavailable');
            }
        });
        $this->app->forgetInstance(ApiKeyAuthenticator::class);
        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();
    }

    public function test_last_used_write_failures_do_not_fail_authentication(): void
    {
        $user = User::factory()->create();
        [, $plainTextKey] = $this->key($user, ['urls:read']);
        Log::spy();
        DB::beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'update "api_keys"')) {
                throw new RuntimeException('Sensitive database failure');
            }
        });

        $this->withToken($plainTextKey)->getJson('/api/v1/urls')->assertOk();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('api_key_last_used_update_failed', [
                'exception_class' => RuntimeException::class,
            ]);
    }

    /** @param list<string> $scopes
     * @return array{ApiKey, string}
     */
    private function key(User $user, array $scopes, ?string $expiresAt = null): array
    {
        $result = app(ApiKeyService::class)->create($user, 'Automation', $scopes, $expiresAt);

        return [$result['api_key'], $result['plain_text_key']];
    }

    private function url(User $owner, string $shortCode, string $longUrl): Url
    {
        return Url::create([
            'owner_id' => $owner->id,
            'short_code' => $shortCode,
            'long_url' => $longUrl,
        ]);
    }
}
