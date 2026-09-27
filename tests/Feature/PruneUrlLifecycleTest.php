<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Models\IdempotencyKey;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class PruneUrlLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_eligible_records_without_mutating_them(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $url = $this->deletedUrl('dry-run', CarbonImmutable::now()->subDays(31));
        $key = $this->idempotencyKey('dry-run', CarbonImmutable::now()->subDays(31));
        $metrics = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $metrics);
        Log::spy();

        $this->artisan('urls:prune-lifecycle', [
            '--dry-run' => true,
            '--batch-size' => 1,
            '--retention-days' => 30,
        ])->assertSuccessful();

        $this->assertNotNull(Url::onlyTrashed()->find($url->id));
        $this->assertNotNull(IdempotencyKey::find($key->id));
        foreach (['url', 'idempotency_key'] as $recordType) {
            foreach (['examined', 'skipped'] as $outcome) {
                $this->assertTrue($metrics->hasCounter('lifecycle_cleanup_total', [
                    'record_type' => $recordType,
                    'outcome' => $outcome,
                ]));
            }
        }
        Log::shouldHaveReceived('info')->once()->with(
            'url_lifecycle_cleanup_completed',
            \Mockery::on(fn (array $context): bool => $context['dry_run'] === true
                && $context['examined'] === 2
                && $context['deleted'] === 0
                && $context['skipped'] === 2
                && $context['failed'] === 0),
        );
    }

    public function test_cleanup_honors_the_cutoff_uses_bounded_batches_and_is_repeatable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $eligibleUrls = collect(range(1, 5))->map(
            fn (int $index): Url => $this->deletedUrl("old-{$index}", CarbonImmutable::now()->subDays(31)),
        );
        $boundaryUrl = $this->deletedUrl('boundary', CarbonImmutable::now()->subDays(30));
        $recoverableUrl = $this->deletedUrl('recoverable', CarbonImmutable::now()->subDays(30)->addSecond());
        $activeUrl = Url::create([
            'short_code' => 'active',
            'long_url' => 'https://active.example',
        ]);
        $oldKey = $this->idempotencyKey('old-key', CarbonImmutable::now()->subDays(31));
        $boundaryKey = $this->idempotencyKey('boundary-key', CarbonImmutable::now()->subDays(30));
        $recentKey = $this->idempotencyKey('recent-key', CarbonImmutable::now()->subDays(29));

        $arguments = ['--batch-size' => 2, '--retention-days' => 30];
        $this->artisan('urls:prune-lifecycle', $arguments)->assertSuccessful();

        foreach ($eligibleUrls->push($boundaryUrl) as $url) {
            $this->assertNull(Url::withTrashed()->find($url->id));
        }
        $this->assertNotNull(Url::onlyTrashed()->find($recoverableUrl->id));
        $this->assertNotNull(Url::find($activeUrl->id));
        $this->assertNull(IdempotencyKey::find($oldKey->id));
        $this->assertNull(IdempotencyKey::find($boundaryKey->id));
        $this->assertNotNull(IdempotencyKey::find($recentKey->id));

        $this->artisan('urls:prune-lifecycle', $arguments)->assertSuccessful();

        $this->assertNotNull(Url::onlyTrashed()->find($recoverableUrl->id));
        $this->assertNotNull(Url::find($activeUrl->id));
        $this->assertNotNull(IdempotencyKey::find($recentKey->id));
    }

    public function test_invalid_cleanup_options_fail_without_mutation(): void
    {
        $url = $this->deletedUrl('invalid-options', CarbonImmutable::now()->subDays(90));

        $this->artisan('urls:prune-lifecycle', ['--batch-size' => 0])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('urls:prune-lifecycle', ['--retention-days' => 'invalid'])
            ->assertExitCode(Command::FAILURE);

        $this->assertNotNull(Url::onlyTrashed()->find($url->id));
    }

    public function test_a_record_failure_is_logged_and_does_not_abort_later_batches(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $url = $this->deletedUrl('blocked', CarbonImmutable::now()->subDays(31));
        $key = $this->idempotencyKey('still-pruned', CarbonImmutable::now()->subDays(31));
        DB::statement(<<<'SQL'
            CREATE TRIGGER prevent_url_delete
            BEFORE DELETE ON urls
            BEGIN
                SELECT RAISE(ABORT, 'blocked by test');
            END
            SQL);
        Log::spy();

        $this->artisan('urls:prune-lifecycle', [
            '--batch-size' => 1,
            '--retention-days' => 30,
        ])->assertExitCode(Command::FAILURE);

        $this->assertNotNull(Url::onlyTrashed()->find($url->id));
        $this->assertNull(IdempotencyKey::find($key->id));
        Log::shouldHaveReceived('error')->once()->with(
            'url_lifecycle_cleanup_record_failed',
            \Mockery::on(fn (array $context): bool => $context['record_type'] === 'url'
                && $context['record_id'] === $url->id),
        );
        Log::shouldHaveReceived('info')->once()->with(
            'url_lifecycle_cleanup_completed',
            \Mockery::on(fn (array $context): bool => $context['failed'] === 1
                && $context['deleted'] === 1),
        );
    }

    public function test_cleanup_is_scheduled_daily_with_overlap_and_single_server_protection(): void
    {
        $event = collect(Schedule::events())->first(
            fn ($event): bool => str_contains($event->command ?? '', 'urls:prune-lifecycle'),
        );

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('--batch-size=100', $event->command);
        $this->assertStringContainsString('--retention-days=30', $event->command);
    }

    private function deletedUrl(string $shortCode, CarbonImmutable $deletedAt): Url
    {
        $url = Url::create([
            'short_code' => $shortCode,
            'long_url' => "https://{$shortCode}.example",
        ]);
        $url->delete();
        Url::onlyTrashed()->whereKey($url->id)->update(['deleted_at' => $deletedAt]);

        return $url;
    }

    private function idempotencyKey(string $key, CarbonImmutable $createdAt): IdempotencyKey
    {
        $record = IdempotencyKey::create([
            'key' => $key,
            'request_hash' => hash('sha256', $key),
            'response' => ['short_code' => $key],
        ]);
        IdempotencyKey::whereKey($record->id)->update(['created_at' => $createdAt]);

        return $record;
    }
}
