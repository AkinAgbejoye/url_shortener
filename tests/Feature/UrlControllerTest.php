<?php

namespace Tests\Feature;

use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class UrlControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shortens_a_url(): void
    {
        Cache::clear();

        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/a/long/path',
        ]);

        $response->assertCreated()
            ->assertJsonPath('short_code', '1')
            ->assertJsonPath('long_url', 'https://example.com/a/long/path')
            ->assertJsonPath('short_url', 'http://localhost/1')
            ->assertJsonPath('expires_at', null)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('urls', [
            'short_code' => '1',
            'long_url' => 'https://example.com/a/long/path',
        ]);
        $this->assertSame('https://example.com/a/long/path', Cache::get('url:1')['long_url']);
    }

    public function test_it_returns_the_created_url_when_the_cache_write_fails(): void
    {
        app(RateLimiter::class);
        Log::spy();
        Cache::shouldReceive('put')
            ->once()
            ->with(
                'url:1',
                \Mockery::on(fn (array $payload): bool => $payload['version'] === 1
                    && $payload['long_url'] === 'https://example.com/cache-failure'
                    && $payload['expires_at'] === null),
                \Mockery::type(\DateTimeInterface::class),
            )
            ->andThrow(new RuntimeException('Redis write failed'));

        $this->withHeader('X-Request-ID', 'cache-write-request')
            ->postJson('/api/v1/urls', [
                'long_url' => 'https://example.com/cache-failure',
            ])
            ->assertCreated()
            ->assertJsonPath('short_code', '1');

        $this->assertDatabaseHas('urls', [
            'short_code' => '1',
            'long_url' => 'https://example.com/cache-failure',
        ]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('url_cache_operation_failed', \Mockery::on(
                fn (array $context): bool => $context['short_code'] === '1'
                    && $context['exception'] === 'Redis write failed'
                    && $context['request_id'] === 'cache-write-request'
                    && $context['url_path'] === 'api/v1/urls'
            ));
    }

    public function test_it_validates_the_long_url(): void
    {
        $this->postJson('/api/v1/urls', ['long_url' => 'not-a-url'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The long URL must be a valid HTTP or HTTPS URL.',
                'errors' => [
                    'long_url' => ['The long URL must be a valid HTTP or HTTPS URL.'],
                ],
            ]);
    }

    public function test_it_only_accepts_http_and_https_urls(): void
    {
        $this->postJson('/api/v1/urls', ['long_url' => 'ftp://example.com/file'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('long_url');
    }

    public function test_it_creates_normalizes_caches_and_redirects_a_custom_alias(): void
    {
        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/product-launch',
            'custom_alias' => '  Product-Launch  ',
        ])->assertCreated()
            ->assertJsonPath('short_code', 'product-launch')
            ->assertJsonPath('short_url', 'http://localhost/product-launch');
        $managementToken = $response->headers->get('X-Management-Token');

        $this->assertDatabaseHas('urls', [
            'short_code' => 'product-launch',
            'is_custom' => true,
        ]);
        $this->assertSame(
            'https://example.com/product-launch',
            Cache::get('url:product-launch')['long_url'],
        );
        $this->get('/product-launch')->assertRedirect('https://example.com/product-launch');
        $this->withHeader('X-Management-Token', $managementToken)
            ->getJson('/api/v1/urls/product-launch')
            ->assertOk()
            ->assertJsonPath('short_code', 'product-launch');
    }

    public function test_it_returns_a_field_specific_conflict_for_a_claimed_alias(): void
    {
        $payload = [
            'long_url' => 'https://example.com/first',
            'custom_alias' => 'campaign',
        ];
        $this->postJson('/api/v1/urls', $payload)->assertCreated();

        $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/second',
            'custom_alias' => 'CAMPAIGN',
        ])->assertConflict()->assertExactJson([
            'message' => 'The custom alias has already been taken.',
            'errors' => [
                'custom_alias' => ['The custom alias has already been taken.'],
            ],
        ]);

        $this->assertDatabaseCount('urls', 1);
    }

    public function test_it_validates_custom_alias_policy_at_the_api_boundary(): void
    {
        foreach ([
            ['up', 'The custom alias is reserved and cannot be used.'],
            ['API', 'The custom alias is reserved and cannot be used.'],
            ['bad/alias', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
        ] as [$alias, $message]) {
            $this->postJson('/api/v1/urls', [
                'long_url' => 'https://example.com',
                'custom_alias' => $alias,
            ])->assertUnprocessable()
                ->assertJsonPath('message', $message)
                ->assertJsonPath('errors.custom_alias.0', $message);
        }

        $this->assertDatabaseCount('urls', 0);
    }

    public function test_idempotency_replays_the_same_alias_and_conflicts_when_it_changes(): void
    {
        $headers = ['Idempotency-Key' => 'custom-alias-request'];
        $payload = [
            'long_url' => 'https://example.com/campaign',
            'custom_alias' => 'Product-Launch',
        ];

        $created = $this->postJson('/api/v1/urls', $payload, $headers)->assertCreated();
        $replayed = $this->postJson('/api/v1/urls', [
            ...$payload,
            'custom_alias' => 'product-launch',
        ], $headers)->assertOk();
        $this->assertSame($created->json(), $replayed->json());

        $this->postJson('/api/v1/urls', [
            ...$payload,
            'custom_alias' => 'another-launch',
        ], $headers)->assertConflict()
            ->assertJsonPath('message', 'This idempotency key was already used with a different request.');
        $this->assertDatabaseCount('urls', 1);
    }

    public function test_it_normalizes_and_persists_a_valid_expiration_in_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T08:00:00Z'));

        $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/expiring',
            'expires_at' => '2026-09-28T10:30:00+02:00',
        ])->assertCreated()
            ->assertJsonPath('expires_at', '2026-09-28T08:30:00+00:00')
            ->assertJsonPath('status', 'active');

        $url = Url::where('short_code', '1')->firstOrFail();
        $this->assertSame('2026-09-28T08:30:00+00:00', $url->expires_at->toIso8601String());
        $this->assertSame('2026-09-28T08:30:00+00:00', Cache::get('url:1')['expires_at']);
    }

    public function test_it_rejects_malformed_past_and_excessive_expirations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T08:00:00Z'));
        config(['url_shortener.max_lifetime_days' => 30]);

        $invalidExpirations = [
            ['tomorrow', 'The expiration must be a valid ISO-8601 timestamp with a timezone.'],
            ['2026-09-27T07:59:59Z', 'The expiration must be in the future.'],
            ['2026-10-28T08:00:00Z', 'The expiration may not be more than 30 days in the future.'],
        ];

        foreach ($invalidExpirations as [$expiresAt, $message]) {
            $this->postJson('/api/v1/urls', [
                'long_url' => 'https://example.com/invalid-expiration',
                'expires_at' => $expiresAt,
            ])->assertUnprocessable()
                ->assertJsonPath('message', $message)
                ->assertJsonPath('errors.expires_at.0', $message);
        }

        $this->assertDatabaseCount('urls', 0);
    }

    public function test_idempotency_replays_equivalent_expiration_instants_and_conflicts_on_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T08:00:00Z'));
        $headers = ['Idempotency-Key' => 'expiring-request'];

        $original = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/idempotent-expiration',
            'expires_at' => '2026-09-28T10:00:00+02:00',
        ], $headers)->assertCreated();

        $replay = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/idempotent-expiration',
            'expires_at' => '2026-09-28T08:00:00Z',
        ], $headers)->assertOk();

        $this->assertSame($original->json(), $replay->json());

        $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/idempotent-expiration',
            'expires_at' => '2026-09-29T08:00:00Z',
        ], $headers)->assertConflict();
        $this->assertDatabaseCount('urls', 1);
    }

    public function test_an_idempotency_key_replays_the_original_response(): void
    {
        $headers = ['Idempotency-Key' => 'create-homepage'];
        $payload = ['long_url' => 'https://example.com'];

        $original = $this->postJson('/api/v1/urls', $payload, $headers)->assertCreated();
        $replay = $this->postJson('/api/v1/urls', $payload, $headers)->assertOk();

        $this->assertSame($original->json(), $replay->json());
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_an_empty_idempotency_key_is_treated_as_absent(): void
    {
        $headers = ['Idempotency-Key' => ''];
        $payload = ['long_url' => 'https://example.com/unkeyed'];

        $this->postJson('/api/v1/urls', $payload, $headers)->assertCreated();
        $this->postJson('/api/v1/urls', $payload, $headers)->assertCreated();

        $this->assertDatabaseCount('urls', 2);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_an_idempotency_key_cannot_be_reused_for_another_url(): void
    {
        $headers = ['Idempotency-Key' => 'same-key'];

        $this->postJson('/api/v1/urls', ['long_url' => 'https://first.example'], $headers)
            ->assertCreated();

        $this->postJson('/api/v1/urls', ['long_url' => 'https://second.example'], $headers)
            ->assertConflict()
            ->assertJsonPath('message', 'This idempotency key was already used with a different request.');

        $this->assertDatabaseCount('urls', 1);
    }

    public function test_it_rejects_an_oversized_idempotency_key(): void
    {
        $this->postJson(
            '/api/v1/urls',
            ['long_url' => 'https://example.com'],
            ['Idempotency-Key' => str_repeat('a', 256)],
        )->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The Idempotency-Key header may not be greater than 255 characters.',
                'errors' => [
                    'idempotency_key' => ['The Idempotency-Key header may not be greater than 255 characters.'],
                ],
            ]);
    }

    public function test_it_redirects_from_the_cache(): void
    {
        Cache::put('url:cached', [
            'version' => 1,
            'long_url' => 'https://cached.example',
            'expires_at' => null,
        ]);

        $this->get('/cached')
            ->assertRedirect('https://cached.example');
    }

    public function test_it_rebuilds_the_cache_from_the_database(): void
    {
        Url::create(['short_code' => 'database', 'long_url' => 'https://database.example']);
        Cache::forget('url:database');

        $this->get('/database')
            ->assertRedirect('https://database.example');

        $this->assertSame('https://database.example', Cache::get('url:database')['long_url']);
    }

    public function test_an_expired_url_does_not_redirect(): void
    {
        Url::create([
            'short_code' => 'expired',
            'long_url' => 'https://expired.example',
            'expires_at' => CarbonImmutable::now()->subSecond(),
        ]);

        $this->get('/expired')->assertNotFound();
        $this->assertNull(Cache::get('url:expired'));
    }

    public function test_a_disabled_url_does_not_redirect(): void
    {
        Url::create([
            'short_code' => 'disabled',
            'long_url' => 'https://disabled.example',
            'disabled_at' => CarbonImmutable::now(),
        ]);

        $this->get('/disabled')->assertNotFound();
    }

    public function test_a_soft_deleted_url_does_not_redirect(): void
    {
        $url = Url::create([
            'short_code' => 'deleted',
            'long_url' => 'https://deleted.example',
        ]);
        $url->delete();

        $this->get('/deleted')->assertNotFound();
    }

    public function test_a_legacy_cache_value_cannot_bypass_an_expired_database_record(): void
    {
        Url::create([
            'short_code' => 'stale',
            'long_url' => 'https://stale.example',
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
        Cache::put('url:stale', 'https://stale.example', now()->addHour());

        $this->get('/stale')->assertNotFound();
        $this->assertNull(Cache::get('url:stale'));
    }

    public function test_it_falls_back_to_the_database_and_logs_a_cache_failure(): void
    {
        Url::create(['short_code' => 'fallback', 'long_url' => 'https://fallback.example']);
        Log::spy();
        Cache::shouldReceive('get')
            ->once()
            ->with('url:fallback')
            ->andThrow(new RuntimeException('Redis unavailable'));

        $this->withHeader('X-Request-ID', 'test-request-id')
            ->get('/fallback')
            ->assertRedirect('https://fallback.example');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('url_cache_operation_failed', \Mockery::on(
                fn (array $context): bool => $context['short_code'] === 'fallback'
                    && $context['exception'] === 'Redis unavailable'
                    && $context['request_id'] === 'test-request-id'
                    && $context['url_path'] === 'fallback'
            ));
    }

    public function test_responses_include_a_request_id_for_log_correlation(): void
    {
        $this->withHeader('X-Request-ID', 'client-request-id')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'client-request-id');
    }

    public function test_an_unknown_short_code_returns_not_found(): void
    {
        $this->get('/missing')->assertNotFound();
    }

    public function test_the_health_endpoint_is_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_url_creation_is_rate_limited(): void
    {
        Cache::clear();

        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/v1/urls', [
                'long_url' => "https://example.com/{$attempt}",
            ])->assertCreated();
        }

        $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/limited',
        ])->assertTooManyRequests();
    }
}
