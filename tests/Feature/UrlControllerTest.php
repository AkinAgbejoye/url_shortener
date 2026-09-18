<?php

namespace Tests\Feature;

use App\Models\Url;
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
            ->assertJsonPath('short_url', 'http://localhost/1');

        $this->assertDatabaseHas('urls', [
            'short_code' => '1',
            'long_url' => 'https://example.com/a/long/path',
        ]);
        $this->assertSame('https://example.com/a/long/path', Cache::get('url:1'));
    }

    public function test_it_validates_the_long_url(): void
    {
        $this->postJson('/api/v1/urls', ['long_url' => 'not-a-url'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('long_url');
    }

    public function test_it_only_accepts_http_and_https_urls(): void
    {
        $this->postJson('/api/v1/urls', ['long_url' => 'ftp://example.com/file'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('long_url');
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
        )->assertUnprocessable();
    }

    public function test_it_redirects_from_the_cache(): void
    {
        Cache::put('url:cached', 'https://cached.example');

        $this->get('/cached')
            ->assertRedirect('https://cached.example');
    }

    public function test_it_rebuilds_the_cache_from_the_database(): void
    {
        Url::create(['short_code' => 'database', 'long_url' => 'https://database.example']);
        Cache::forget('url:database');

        $this->get('/database')
            ->assertRedirect('https://database.example');

        $this->assertSame('https://database.example', Cache::get('url:database'));
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
}
