<?php

namespace Tests\Unit;

use App\Models\Url;
use App\Models\UrlAnalyticsDaily;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UrlAnalyticsDailyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_only_typed_daily_aggregate_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T23:59:59Z'));
        $url = $this->url('aggregate');

        $bucket = $url->dailyAnalytics()->create([
            'date' => CarbonImmutable::now('UTC')->startOfDay(),
            'redirect_count' => 7,
        ])->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $bucket->date);
        $this->assertSame('2026-09-27', $bucket->date->format('Y-m-d'));
        $this->assertSame(7, $bucket->redirect_count);
        $this->assertTrue($bucket->url->is($url));
        $this->assertTrue($url->fresh()->dailyAnalytics->first()->is($bucket));
        $this->assertSame([
            'id',
            'url_id',
            'date',
            'redirect_count',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('url_analytics_daily'));
    }

    public function test_a_url_can_have_only_one_bucket_per_utc_date(): void
    {
        $url = $this->url('unique');
        $attributes = ['date' => '2026-09-27', 'redirect_count' => 1];
        $url->dailyAnalytics()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        $url->dailyAnalytics()->create($attributes);
    }

    public function test_soft_deletion_retains_analytics_until_the_url_is_hard_deleted(): void
    {
        $url = $this->url('cascade');
        $bucket = $url->dailyAnalytics()->create([
            'date' => '2026-09-27',
            'redirect_count' => 3,
        ]);

        $url->delete();

        $this->assertNotNull(UrlAnalyticsDaily::find($bucket->id));

        $url->forceDelete();

        $this->assertNull(UrlAnalyticsDaily::find($bucket->id));
    }

    public function test_migration_rolls_back_and_reapplies_without_changing_existing_urls(): void
    {
        $url = $this->url('legacy');
        $migration = require database_path(
            'migrations/2026_09_27_130000_create_url_analytics_daily_table.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('url_analytics_daily'));
        $this->assertSame('legacy', Url::findOrFail($url->id)->short_code);

        $migration->up();

        $this->assertTrue(Schema::hasTable('url_analytics_daily'));
        $this->assertTrue(Schema::hasIndex('url_analytics_daily', ['url_id', 'date'], 'unique'));
        $this->assertTrue(Schema::hasIndex('url_analytics_daily', ['date']));
        $this->assertSame('legacy', Url::findOrFail($url->id)->short_code);
    }

    public function test_analytics_limits_have_bounded_defaults(): void
    {
        $this->assertSame(90, config('url_shortener.analytics.max_query_days'));
        $this->assertSame(365, config('url_shortener.analytics.retention_days'));
    }

    private function url(string $shortCode): Url
    {
        return Url::create([
            'short_code' => $shortCode,
            'long_url' => "https://{$shortCode}.example",
        ]);
    }
}
