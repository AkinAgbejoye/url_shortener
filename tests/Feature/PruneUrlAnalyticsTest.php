<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class PruneUrlAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private RecordingMetricsExporter $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('url_shortener.analytics.max_query_days', 7);
        $this->metrics = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $this->metrics);
    }

    public function test_the_newest_bucket_before_the_utc_cutoff_is_kept_all_day(): void
    {
        foreach (['2026-09-28T00:00:00Z', '2026-09-28T23:59:59Z'] as $now) {
            $this->travelTo(CarbonImmutable::parse($now));
            $url = Url::create(['short_code' => 'c'.md5($now), 'long_url' => 'https://cutoff.example']);
            $expired = $this->bucket($url, '2026-08-28');
            $newestRecoverable = $this->bucket($url, '2026-08-29');
            $today = $this->bucket($url, '2026-09-28');

            $this->artisan('urls:prune-analytics', ['--retention-days' => 30])->assertSuccessful();

            $this->assertFalse($this->bucketExists($expired));
            $this->assertTrue($this->bucketExists($newestRecoverable));
            $this->assertTrue($this->bucketExists($today));
        }
    }

    public function test_dry_run_reports_eligible_buckets_without_mutating_them(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $url = $this->url('dry-run');
        $old = collect(['2026-08-01', '2026-08-02', '2026-08-03'])
            ->map(fn (string $date): int => $this->bucket($url, $date));
        Log::spy();

        $this->artisan('urls:prune-analytics', [
            '--dry-run' => true,
            '--batch-size' => 2,
            '--retention-days' => 30,
        ])->assertSuccessful();

        $old->each(fn (int $id) => $this->assertTrue($this->bucketExists($id)));
        $this->assertTrue($this->metrics->hasCounter('analytics_cleanup_total', [
            'scope' => 'batch',
            'outcome' => 'skipped',
        ]));
        $this->assertFalse($this->metrics->hasCounter('analytics_cleanup_total', [
            'scope' => 'batch',
            'outcome' => 'deleted',
        ]));
        Log::shouldHaveReceived('info')->once()->with(
            'url_analytics_cleanup_completed',
            \Mockery::on(fn (array $context): bool => $context['dry_run'] === true
                && $context['cutoff_date'] === '2026-08-29'
                && $context['examined'] === 3
                && $context['deleted'] === 0
                && $context['skipped'] === 3
                && $context['failed'] === 0),
        );
    }

    public function test_cleanup_deletes_in_bounded_batches_and_is_repeatable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $url = $this->url('batches');
        $expired = collect(range(1, 5))->map(
            fn (int $day): int => $this->bucket($url, sprintf('2026-08-%02d', $day)),
        );
        $retained = $this->bucket($url, '2026-09-01');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'delete')) {
                $queries[] = $query->bindings;
            }
        });

        $arguments = ['--batch-size' => 2, '--retention-days' => 30];
        $this->artisan('urls:prune-analytics', $arguments)->assertSuccessful();

        $expired->each(fn (int $id) => $this->assertFalse($this->bucketExists($id)));
        $this->assertTrue($this->bucketExists($retained));
        $this->assertCount(3, $queries);
        foreach ($queries as $bindings) {
            $this->assertLessThanOrEqual(3, count($bindings));
        }
        $this->assertSame(3, collect($this->metrics->counters)->where('labels', [
            'scope' => 'batch',
            'outcome' => 'deleted',
        ])->count());

        Log::spy();
        $this->artisan('urls:prune-analytics', $arguments)->assertSuccessful();

        $this->assertTrue($this->bucketExists($retained));
        $this->assertNotNull(Url::find($url->id));
        Log::shouldHaveReceived('info')->once()->with(
            'url_analytics_cleanup_completed',
            \Mockery::on(fn (array $context): bool => $context['examined'] === 0
                && $context['deleted'] === 0
                && $context['failed'] === 0),
        );
    }

    public function test_invalid_options_fail_without_mutation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $bucket = $this->bucket($this->url('invalid'), '2020-01-01');

        foreach ([
            ['--batch-size' => 0],
            ['--batch-size' => 1001],
            ['--batch-size' => 'many'],
            ['--retention-days' => 'invalid'],
            ['--retention-days' => 6],
            ['--retention-days' => 3651],
        ] as $options) {
            $this->artisan('urls:prune-analytics', $options)->assertExitCode(Command::FAILURE);
        }

        $this->assertTrue($this->bucketExists($bucket));
        $this->assertSame([], $this->metrics->counters);
    }

    public function test_a_failed_batch_is_logged_without_identifiers_and_later_batches_continue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $blockedUrl = $this->url('blocked');
        $otherUrl = $this->url('other');
        $blocked = $this->bucket($blockedUrl, '2026-08-01');
        $pruned = $this->bucket($otherUrl, '2026-08-01');
        DB::statement(<<<SQL
            CREATE TRIGGER prevent_analytics_delete
            BEFORE DELETE ON url_analytics_daily
            WHEN OLD.url_id = {$blockedUrl->id}
            BEGIN
                SELECT RAISE(ABORT, 'blocked by test');
            END
            SQL);
        Log::spy();

        $this->artisan('urls:prune-analytics', [
            '--batch-size' => 1,
            '--retention-days' => 30,
        ])->assertExitCode(Command::FAILURE);

        $this->assertTrue($this->bucketExists($blocked));
        $this->assertFalse($this->bucketExists($pruned));
        Log::shouldHaveReceived('error')->once()->with(
            'url_analytics_cleanup_batch_failed',
            \Mockery::on(fn (array $context): bool => array_keys($context) === [
                'batch', 'examined', 'deleted', 'skipped', 'failed', 'exception_class',
            ] && $context['failed'] === 1),
        );
        Log::shouldHaveReceived('info')->once()->with(
            'url_analytics_cleanup_completed',
            \Mockery::on(fn (array $context): bool => $context['failed'] === 1
                && $context['deleted'] === 1),
        );
        $this->assertTrue($this->metrics->hasCounter('analytics_cleanup_total', [
            'scope' => 'batch',
            'outcome' => 'failed',
        ]));
        $this->assertTrue($this->metrics->hasCounter('analytics_cleanup_total', [
            'scope' => 'run',
            'outcome' => 'failed',
        ]));
        foreach ($this->metrics->counters as $counter) {
            $this->assertSame(['scope', 'outcome'], array_keys($counter['labels']));
        }
    }

    public function test_lifecycle_cleanup_still_cascades_retained_analytics(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T12:00:00Z'));
        $url = $this->url('cascade');
        $recent = $this->bucket($url, '2026-09-20');
        $url->delete();
        Url::onlyTrashed()->whereKey($url->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('urls:prune-analytics', ['--retention-days' => 30])->assertSuccessful();
        $this->assertTrue($this->bucketExists($recent));

        $this->artisan('urls:prune-lifecycle', ['--retention-days' => 30])->assertSuccessful();
        $this->assertFalse($this->bucketExists($recent));
    }

    public function test_cleanup_is_scheduled_daily_with_overlap_and_single_server_protection(): void
    {
        $event = collect(Schedule::events())->first(
            fn ($event): bool => str_contains($event->command ?? '', 'urls:prune-analytics'),
        );

        $this->assertNotNull($event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('--batch-size=500', $event->command);
        $this->assertStringContainsString('--retention-days=365', $event->command);
    }

    private function url(string $shortCode): Url
    {
        return Url::create([
            'short_code' => $shortCode,
            'long_url' => "https://{$shortCode}.example",
        ]);
    }

    private function bucket(Url $url, string $date): int
    {
        return DB::table('url_analytics_daily')->insertGetId([
            'url_id' => $url->id,
            'date' => $date,
            'redirect_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bucketExists(int $id): bool
    {
        return DB::table('url_analytics_daily')->where('id', $id)->exists();
    }
}
