<?php

namespace Tests\Unit;

use App\Models\Url;
use App\Services\UrlResolver;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UrlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_a_value_filled_by_another_request_after_acquiring_the_lock(): void
    {
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, \Mockery::type(\Closure::class))
            ->andReturnUsing(fn (int $seconds, callable $callback): mixed => $callback());

        Cache::shouldReceive('get')
            ->twice()
            ->with('url:concurrent')
            ->andReturn(null, 'https://concurrent.example');
        Cache::shouldReceive('lock')
            ->once()
            ->with('lock:cache-rebuild:concurrent', 10)
            ->andReturn($lock);

        $this->assertSame(
            'https://concurrent.example',
            app(UrlResolver::class)->resolve('concurrent'),
        );
    }

    public function test_it_logs_a_lock_timeout_and_falls_back_to_the_database(): void
    {
        Url::create([
            'short_code' => 'locked',
            'long_url' => 'https://locked.example',
        ]);
        Log::spy();

        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, \Mockery::type(\Closure::class))
            ->andThrow(new LockTimeoutException('Cache lock timed out'));

        Cache::shouldReceive('get')
            ->once()
            ->with('url:locked')
            ->andReturnNull();
        Cache::shouldReceive('lock')
            ->once()
            ->with('lock:cache-rebuild:locked', 10)
            ->andReturn($lock);

        $this->assertSame(
            'https://locked.example',
            app(UrlResolver::class)->resolve('locked'),
        );
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('url_cache_lock_timeout', \Mockery::on(
                fn (array $context): bool => $context['short_code'] === 'locked'
                    && $context['exception'] === 'Cache lock timed out'
                    && array_key_exists('request_id', $context)
                    && array_key_exists('url_path', $context)
            ));
    }
}
