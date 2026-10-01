<?php

namespace Tests\Unit;

use App\Services\ApiKeyAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiKeyAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_credentials_still_perform_a_dummy_hash_check(): void
    {
        Hash::shouldReceive('check')
            ->once()
            ->with('', \Mockery::type('string'))
            ->andReturnFalse();

        $result = app(ApiKeyAuthenticator::class)->authenticate('Bearer malformed');

        $this->assertNull($result['api_key']);
        $this->assertSame('malformed', $result['outcome']);
    }

    public function test_unknown_public_ids_still_perform_a_dummy_hash_check(): void
    {
        $secret = str_repeat('x', 64);
        Hash::shouldReceive('check')
            ->once()
            ->with($secret, \Mockery::type('string'))
            ->andReturnFalse();

        $result = app(ApiKeyAuthenticator::class)->authenticate(
            'Bearer ak_'.str_repeat('z', 20).'.'.$secret,
        );

        $this->assertNull($result['api_key']);
        $this->assertSame('unknown', $result['outcome']);
    }
}
