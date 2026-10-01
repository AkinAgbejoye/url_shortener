<?php

namespace Tests\Feature;

use App\Models\Url;
use App\Models\UrlAnalyticsDaily;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_page_is_authenticated_and_accessible(): void
    {
        $this->get('/account')->assertRedirect('/login');

        $user = User::factory()->create(['email' => 'owner@example.com']);
        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('Your links')
            ->assertSee('Create an owned link')
            ->assertSee('id="claim-link-form"', false)
            ->assertSee('id="dashboard-status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('data-endpoint="http://localhost/account/urls"', false)
            ->assertDontSee('management_token');
    }

    public function test_listing_is_owner_scoped_bounded_and_deterministically_paginated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
        $owner = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 27) as $number) {
            Url::create([
                'owner_id' => $owner->id,
                'short_code' => "owned-{$number}",
                'long_url' => "https://owner.example/{$number}",
                'management_token_hash' => hash('sha256', "secret-{$number}"),
            ]);
        }
        Url::create([
            'owner_id' => $other->id,
            'short_code' => 'other-owner',
            'long_url' => 'https://other.example',
        ]);
        Url::create([
            'short_code' => 'anonymous',
            'long_url' => 'https://anonymous.example',
            'management_token_hash' => hash('sha256', 'anonymous-secret'),
        ]);
        $newest = Url::where('short_code', 'owned-27')->firstOrFail();
        UrlAnalyticsDaily::create([
            'url_id' => $newest->id,
            'date' => '2026-10-01',
            'redirect_count' => 7,
        ]);

        $firstPage = $this->actingAs($owner)->getJson('/account/urls?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 27)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.short_code', 'owned-27')
            ->assertJsonPath('data.0.total_redirects', 7)
            ->assertJsonPath('data.24.short_code', 'owned-3');

        $secondPage = $this->actingAs($owner)->getJson('/account/urls?per_page=100&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.short_code', 'owned-2')
            ->assertJsonPath('data.1.short_code', 'owned-1');

        $json = $firstPage->getContent().$secondPage->getContent();
        $this->assertStringNotContainsString('other-owner', $json);
        $this->assertStringNotContainsString('anonymous', $json);
        $this->assertStringNotContainsString('management_token', $json);
        $this->assertStringNotContainsString('secret-', $json);
    }

    public function test_an_owner_can_create_and_manage_a_link_through_session_routes(): void
    {
        $owner = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/account/urls', [
            'long_url' => 'https://dashboard.example/path',
            'custom_alias' => 'dashboard-link',
        ])->assertCreated()
            ->assertJsonPath('short_code', 'dashboard-link');

        $this->assertNull($created->headers->get('X-Management-Token'));
        $this->assertDatabaseHas('urls', [
            'owner_id' => $owner->id,
            'short_code' => 'dashboard-link',
            'is_custom' => true,
            'management_token_hash' => null,
        ]);

        $this->actingAs($owner)->postJson('/account/urls/dashboard-link/disable')
            ->assertOk()->assertJsonPath('status', 'disabled');
        $this->actingAs($owner)->patchJson('/account/urls/dashboard-link', [
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();
        $this->actingAs($owner)->getJson('/account/urls/dashboard-link/analytics?range=7d')
            ->assertOk()->assertJsonPath('total_redirects', 0);
        $this->actingAs($owner)->deleteJson('/account/urls/dashboard-link')->assertNoContent();
        $this->assertSoftDeleted('urls', ['short_code' => 'dashboard-link']);
    }

    public function test_claim_uses_a_protected_header_and_conflicts_do_not_leak_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = str_repeat('a', 64);
        Url::create([
            'short_code' => 'recent-link',
            'long_url' => 'https://recent.example',
            'management_token_hash' => hash('sha256', $token),
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Management-Token', $token)
            ->postJson('/account/urls/recent-link/claim')
            ->assertOk();

        $this->actingAs($other)
            ->withHeader('X-Management-Token', $token)
            ->postJson('/account/urls/recent-link/claim')
            ->assertNotFound()
            ->assertJsonMissing(['owner_id' => $owner->id]);

        $this->assertDatabaseHas('urls', [
            'short_code' => 'recent-link',
            'owner_id' => $owner->id,
            'management_token_hash' => null,
        ]);
    }

    public function test_dashboard_json_routes_reject_stale_sessions(): void
    {
        $this->getJson('/account/urls')->assertUnauthorized();
        $this->postJson('/account/urls', ['long_url' => 'https://example.com'])->assertUnauthorized();
        $this->postJson('/account/urls/example/claim')->assertUnauthorized();
    }
}
