<?php

namespace Tests\Unit;

use App\Enums\UrlLifecycleState;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_url_is_active_without_a_configured_expiration(): void
    {
        $url = $this->createUrl('active');

        $this->assertTrue($url->isActive());
        $this->assertFalse($url->isExpired());
        $this->assertFalse($url->isDisabled());
        $this->assertSame(UrlLifecycleState::Active, $url->lifecycleState());
    }

    public function test_a_future_expiration_keeps_a_url_active_until_the_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-09-26T12:00:00Z');
        $url = $this->createUrl('scheduled', [
            'expires_at' => $now->addHour(),
        ])->refresh();

        $this->assertTrue($url->isActive($now));
        $this->assertFalse($url->isActive($now->addHour()));
        $this->assertTrue($url->isExpired($now->addHour()));
        $this->assertSame(UrlLifecycleState::Expired, $url->lifecycleState($now->addHour()));
    }

    public function test_a_disabled_url_is_not_active(): void
    {
        $url = $this->createUrl('disabled', [
            'disabled_at' => CarbonImmutable::parse('2026-09-26T12:00:00Z'),
        ])->refresh();

        $this->assertTrue($url->isDisabled());
        $this->assertFalse($url->isActive());
        $this->assertSame(UrlLifecycleState::Disabled, $url->lifecycleState());
    }

    public function test_a_soft_deleted_url_reports_deleted_state(): void
    {
        $url = $this->createUrl('deleted');
        $url->delete();
        $deletedUrl = Url::withTrashed()->findOrFail($url->id);

        $this->assertTrue($deletedUrl->trashed());
        $this->assertFalse($deletedUrl->isActive());
        $this->assertSame(UrlLifecycleState::Deleted, $deletedUrl->lifecycleState());
    }

    public function test_lifecycle_timestamps_are_cast_to_immutable_dates(): void
    {
        $url = $this->createUrl('immutable', [
            'expires_at' => '2026-09-27T12:00:00Z',
            'disabled_at' => '2026-09-26T12:00:00Z',
        ]);
        $url->delete();
        $url = Url::withTrashed()->findOrFail($url->id);

        $this->assertInstanceOf(CarbonImmutable::class, $url->expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $url->disabled_at);
        $this->assertInstanceOf(CarbonImmutable::class, $url->deleted_at);
    }

    public function test_the_active_scope_excludes_every_unavailable_lifecycle_state(): void
    {
        $now = CarbonImmutable::parse('2026-09-26T12:00:00Z');
        $active = $this->createUrl('active-scope');
        $scheduled = $this->createUrl('scheduled-scope', ['expires_at' => $now->addMinute()]);
        $this->createUrl('expired-scope', ['expires_at' => $now]);
        $this->createUrl('disabled-scope', ['disabled_at' => $now]);
        $deleted = $this->createUrl('deleted-scope');
        $deleted->delete();

        $this->assertEqualsCanonicalizing(
            [$active->id, $scheduled->id],
            Url::active($now)->pluck('id')->all(),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function createUrl(string $shortCode, array $attributes = []): Url
    {
        return Url::create([
            'short_code' => $shortCode,
            'long_url' => "https://{$shortCode}.example",
            ...$attributes,
        ]);
    }
}
