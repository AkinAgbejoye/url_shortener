<?php

namespace Tests\Unit;

use App\Models\IdempotencyKey;
use App\Services\UrlShortenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertFalse($replayed['created']);
        $this->assertFalse($replayed['conflict']);
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
}
