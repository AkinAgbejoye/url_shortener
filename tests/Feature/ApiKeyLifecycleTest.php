<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiKeyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_verified_owner_can_create_a_hashed_scoped_api_key_that_is_shown_once(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Deploy bot',
            'scopes' => ['urls:read', 'analytics:read'],
            'expires_at' => now()->addMonth()->toIso8601String(),
            'password' => self::PASSWORD,
        ])->assertCreated()
            ->assertJsonPath('api_key.name', 'Deploy bot')
            ->assertJsonPath('api_key.status', 'active')
            ->assertJsonMissingPath('api_key.secret_hash');

        $plainTextKey = $response->json('plain_text_key');
        $publicId = $response->json('api_key.public_id');
        $secret = str($plainTextKey)->after($publicId.'.')->toString();
        $apiKey = ApiKey::firstOrFail();

        $this->assertStringStartsWith($publicId.'.', $plainTextKey);
        $this->assertNotSame($secret, $apiKey->secret_hash);
        $this->assertTrue(Hash::check($secret, $apiKey->secret_hash));
        $this->assertDatabaseMissing('api_keys', ['secret_hash' => $secret]);

        $this->actingAs($user)->getJson('/account/api-keys')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonMissingPath('data.0.secret_hash')
            ->assertJsonMissingPath('data.0.plain_text_key')
            ->assertJsonMissing(['plain_text_key' => $plainTextKey]);
    }

    public function test_key_scopes_password_expiration_and_active_limit_are_enforced(): void
    {
        config(['url_shortener.api_keys.max_active_per_user' => 1]);
        $user = User::factory()->create(['password' => self::PASSWORD]);
        ApiKey::factory()->for($user)->create();

        $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Duplicate scopes',
            'scopes' => ['urls:read', 'urls:read'],
            'password' => self::PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scopes.0', 'scopes.1']);

        $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Unknown scope',
            'scopes' => ['admin:all'],
            'password' => self::PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scopes.0']);

        $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Wrong password',
            'scopes' => ['urls:read'],
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Too long',
            'scopes' => ['urls:read'],
            'expires_at' => now()->addDays(366)->toIso8601String(),
            'password' => self::PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at']);

        $this->actingAs($user)->postJson('/account/api-keys', [
            'name' => 'Over limit',
            'scopes' => ['urls:read'],
            'password' => self::PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'You can have up to 1 active API keys.');
    }

    public function test_key_listing_is_owner_scoped_metadata_only_and_marks_expired_and_revoked_states(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        ApiKey::factory()->for($owner)->create(['name' => 'Active key']);
        ApiKey::factory()->for($owner)->expired()->create(['name' => 'Expired key']);
        ApiKey::factory()->for($owner)->revoked()->create(['name' => 'Revoked key']);
        ApiKey::factory()->for($other)->create(['name' => 'Other key']);

        $response = $this->actingAs($owner)->getJson('/account/api-keys?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonCount(3, 'data');

        $statuses = collect($response->json('data'))->pluck('status', 'name');
        $this->assertSame('active', $statuses['Active key']);
        $this->assertSame('expired', $statuses['Expired key']);
        $this->assertSame('revoked', $statuses['Revoked key']);
        $this->assertStringNotContainsString('Other key', $response->getContent());
        $this->assertStringNotContainsString('secret_hash', $response->getContent());
    }

    public function test_revocation_is_owner_scoped_password_confirmed_and_idempotent(): void
    {
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        $other = User::factory()->create(['password' => self::PASSWORD]);
        $apiKey = ApiKey::factory()->for($owner)->create();

        $this->actingAs($other)->deleteJson("/account/api-keys/{$apiKey->id}", [
            'password' => self::PASSWORD,
        ])->assertNotFound();

        $this->actingAs($owner)->deleteJson("/account/api-keys/{$apiKey->id}", [
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $revokedAt = $this->actingAs($owner)->deleteJson("/account/api-keys/{$apiKey->id}", [
            'password' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('api_key.status', 'revoked')
            ->json('api_key.revoked_at');

        $this->actingAs($owner)->deleteJson("/account/api-keys/{$apiKey->id}", [
            'password' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('api_key.revoked_at', $revokedAt);
    }

    public function test_only_verified_authenticated_users_can_use_api_key_routes(): void
    {
        $unverified = User::factory()->unverified()->create(['password' => self::PASSWORD]);

        $this->getJson('/account/api-keys')->assertUnauthorized();
        $this->actingAs($unverified)->getJson('/account/api-keys')->assertForbidden();
        $this->actingAs($unverified)->postJson('/account/api-keys', [
            'name' => 'Blocked',
            'scopes' => ['urls:read'],
            'password' => self::PASSWORD,
        ])->assertForbidden();
    }
}
