<?php

namespace Tests\Unit;

use App\Models\IdempotencyKey;
use App\Models\Url;
use App\Services\Base62Service;
use App\Services\UrlShortenerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

    public function test_it_rolls_back_url_creation_when_short_code_generation_fails(): void
    {
        $base62 = new class extends Base62Service
        {
            public function encode(int $number): string
            {
                throw new RuntimeException('Encoding failed.');
            }
        };

        $service = new UrlShortenerService($base62);

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
