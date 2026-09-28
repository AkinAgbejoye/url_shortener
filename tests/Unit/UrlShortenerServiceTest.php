<?php

namespace Tests\Unit;

use App\Exceptions\CustomAliasConflict;
use App\Exceptions\ShortCodeAllocationExhausted;
use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Services\Base62Service;
use App\Services\ShortCodeAllocator;
use App\Services\UrlShortenerService;
use App\Support\OperationalMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class UrlShortenerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replays_a_matching_idempotent_request_without_creating_another_url(): void
    {
        $service = app(UrlShortenerService::class);

        $created = $service->shorten('https://example.com/original', 'request-key');
        $replayed = $service->shorten('https://example.com/original', 'request-key');

        $this->assertTrue($created['created']);
        $this->assertIsString($created['management_token']);
        $this->assertFalse($replayed['created']);
        $this->assertFalse($replayed['conflict']);
        $this->assertNull($replayed['management_token']);
        $this->assertSame($created['response'], $replayed['response']);
        $this->assertDatabaseCount('urls', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_it_flags_a_conflict_when_a_key_is_reused_for_another_url(): void
    {
        $service = app(UrlShortenerService::class);

        $service->shorten('https://example.com/first', 'request-key');
        $conflict = $service->shorten('https://example.com/second', 'request-key');

        $this->assertFalse($conflict['created']);
        $this->assertTrue($conflict['conflict']);
        $this->assertSame('https://example.com/first', $conflict['response']['long_url']);
        $this->assertDatabaseCount('urls', 1);
    }

    public function test_it_persists_a_hash_and_response_for_an_idempotent_request(): void
    {
        $service = app(UrlShortenerService::class);

        $result = $service->shorten('https://example.com/persisted', 'request-key');
        $record = IdempotencyKey::where('key', 'request-key')->firstOrFail();

        $this->assertSame(hash('sha256', 'https://example.com/persisted'), $record->request_hash);
        $this->assertSame($result['response'], $record->response);
    }

    public function test_it_persists_expiration_and_hashes_the_normalized_creation_intent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T08:00:00Z'));
        $service = app(UrlShortenerService::class);
        $expiration = CarbonImmutable::parse('2026-09-28T10:00:00+02:00')->utc();

        $result = $service->shorten(
            'https://example.com/expiring',
            'expiring-key',
            $expiration,
        );
        $record = IdempotencyKey::where('key', 'expiring-key')->firstOrFail();
        $expectedHash = hash('sha256', json_encode([
            'long_url' => 'https://example.com/expiring',
            'expires_at' => '2026-09-28T08:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame($expectedHash, $record->request_hash);
        $this->assertSame('2026-09-28T08:00:00+00:00', $result['response']['expires_at']);
        $this->assertSame('active', $result['response']['status']);
        $this->assertDatabaseHas('urls', ['short_code' => '1']);
        $this->assertSame(
            '2026-09-28T08:00:00+00:00',
            Url::where('short_code', '1')->firstOrFail()->expires_at->toIso8601String(),
        );
    }

    public function test_it_creates_independent_urls_when_no_idempotency_key_is_supplied(): void
    {
        $service = app(UrlShortenerService::class);

        $first = $service->shorten('https://example.com/unkeyed', null);
        $second = $service->shorten('https://example.com/unkeyed', null);

        $this->assertTrue($first['created']);
        $this->assertTrue($second['created']);
        $this->assertNotSame($first['response']['id'], $second['response']['id']);
        $this->assertDatabaseCount('urls', 2);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_it_claims_a_canonical_custom_alias_and_includes_it_in_idempotency(): void
    {
        $service = app(UrlShortenerService::class);

        $created = $service->shorten(
            'https://example.com/campaign',
            'custom-request',
            customAlias: '  Product-Launch  ',
        );
        $replayed = $service->shorten(
            'https://example.com/campaign',
            'custom-request',
            customAlias: 'PRODUCT-LAUNCH',
        );
        $conflict = $service->shorten(
            'https://example.com/campaign',
            'custom-request',
            customAlias: 'another-launch',
        );

        $this->assertSame('product-launch', $created['response']['short_code']);
        $this->assertTrue(Url::where('short_code', 'product-launch')->firstOrFail()->is_custom);
        $this->assertFalse($replayed['created']);
        $this->assertFalse($replayed['conflict']);
        $this->assertSame($created['response'], $replayed['response']);
        $this->assertTrue($conflict['conflict']);
        $this->assertDatabaseCount('urls', 1);
        $this->assertSame(
            hash('sha256', json_encode([
                'long_url' => 'https://example.com/campaign',
                'custom_alias' => 'product-launch',
            ], JSON_THROW_ON_ERROR)),
            IdempotencyKey::where('key', 'custom-request')->firstOrFail()->request_hash,
        );
    }

    public function test_a_claimed_custom_alias_is_rejected_by_the_unique_constraint(): void
    {
        Log::spy();
        $metrics = new RecordingMetricsExporter;
        $this->app->instance(OperationalMetrics::class, new OperationalMetrics($metrics));
        Url::create([
            'short_code' => 'product-launch',
            'long_url' => 'https://existing.example',
            'is_custom' => true,
        ]);

        $this->expectException(CustomAliasConflict::class);

        try {
            app(UrlShortenerService::class)->shorten(
                'https://new.example',
                'colliding-request',
                customAlias: 'product-launch',
            );
        } finally {
            $this->assertDatabaseCount('urls', 1);
            $this->assertDatabaseCount('idempotency_keys', 0);
            $this->assertTrue($metrics->hasCounter('alias_allocations_total', [
                'type' => 'custom',
                'outcome' => 'conflict',
            ]));
            Log::shouldHaveReceived('info')
                ->once()
                ->with('url_alias_allocation_conflict', \Mockery::on(
                    fn (array $context): bool => $context === [
                        'alias_type' => 'custom',
                        'outcome' => 'conflict',
                    ]
                ));
        }
    }

    public function test_generated_allocation_uses_a_deterministic_fallback_after_a_collision(): void
    {
        Log::spy();
        $metrics = new RecordingMetricsExporter;
        Url::create([
            'short_code' => 'claimed',
            'long_url' => 'https://existing.example',
            'is_custom' => true,
        ]);
        $base62 = new class extends Base62Service
        {
            public function encode(int $number): string
            {
                return 'claimed';
            }
        };
        $service = new UrlShortenerService(
            new ShortCodeAllocator($base62, new OperationalMetrics($metrics)),
        );

        $result = $service->shorten('https://generated.example', null);

        $this->assertSame('claimed-1', $result['response']['short_code']);
        $this->assertFalse(Url::where('short_code', 'claimed-1')->firstOrFail()->is_custom);
        $this->assertTrue($metrics->hasCounter('alias_allocations_total', [
            'type' => 'generated',
            'outcome' => 'retry',
        ]));
        $this->assertTrue($metrics->hasCounter('alias_allocations_total', [
            'type' => 'generated',
            'outcome' => 'claimed',
        ]));
        Log::shouldHaveReceived('info')
            ->once()
            ->with('url_alias_allocation_retry', \Mockery::on(
                fn (array $context): bool => $context === [
                    'alias_type' => 'generated',
                    'outcome' => 'retry',
                    'attempt' => 1,
                    'max_attempts' => 20,
                ]
            ));
    }

    public function test_generated_allocation_stops_after_the_configured_attempt_limit(): void
    {
        config()->set('url_shortener.aliases.allocation_attempts', 2);
        foreach (['claimed', 'claimed-1'] as $shortCode) {
            Url::create([
                'short_code' => $shortCode,
                'long_url' => "https://{$shortCode}.example",
                'is_custom' => true,
            ]);
        }
        $base62 = new class extends Base62Service
        {
            public function encode(int $number): string
            {
                return 'claimed';
            }
        };
        $service = new UrlShortenerService(new ShortCodeAllocator($base62));

        $this->expectException(ShortCodeAllocationExhausted::class);

        try {
            $service->shorten('https://generated.example', 'bounded-request');
        } finally {
            $this->assertDatabaseCount('urls', 2);
            $this->assertDatabaseCount('idempotency_keys', 0);
        }
    }

    public function test_it_rolls_back_url_creation_when_short_code_generation_fails(): void
    {
        $base62 = new class extends Base62Service
        {
            public function encode(int $number): string
            {
                throw new RuntimeException('Encoding failed.');
            }
        };

        $service = new UrlShortenerService(new ShortCodeAllocator($base62));

        try {
            $service->shorten('https://example.com/rollback', 'rollback-key');
            $this->fail('Expected short-code generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Encoding failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('urls', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }
}
