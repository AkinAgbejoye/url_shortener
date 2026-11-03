<?php

namespace Tests\Feature;

use App\Contracts\CrawlPageFetcher;
use App\Data\CrawlFetchResult;
use App\Jobs\RunScheduledMonitor;
use App\Models\DomainVerification;
use App\Models\ModerationDomainBlock;
use App\Models\MonitorComparison;
use App\Models\MonitorRun;
use App\Models\MonitorSchedule;
use App\Models\User;
use App\Notifications\MaterialMonitorChange;
use App\Services\BoundedSiteCrawler;
use App\Services\MonitorComparisonService;
use App\Services\MonitorDispatcher;
use App\Services\MonitoringEvidenceCollector;
use App\Services\MonitoringNotifier;
use App\Services\MonitorScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ScheduledMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_creation_is_opt_in_verified_frequency_and_budget_bounded(): void
    {
        CarbonImmutable::setTestNow('2026-11-03T12:00:00Z');
        $owner = User::factory()->create();
        $verification = $this->verified($owner);
        $schedule = app(MonitorScheduleService::class)->create($owner, $verification, 24, 10, 2, ['email_on_material_change' => true]);

        $this->assertSame('active', $schedule->state);
        $this->assertSame(10, $schedule->monthly_run_budget);
        $this->assertTrue($schedule->notification_preferences['email_on_material_change']);
        $this->assertSame(['/login', '/checkout', '/account', '/admin'], $schedule->crawl_policy['exclude_paths']);

        $this->expectException(RuntimeException::class);
        app(MonitorScheduleService::class)->create($owner, $verification, 1, 100, 1, []);
    }

    public function test_dispatch_is_idempotent_records_missed_runs_and_bounds_backfills(): void
    {
        Bus::fake();
        CarbonImmutable::setTestNow('2026-11-10T12:00:00Z');
        $schedule = $this->schedule(User::factory()->create());
        $schedule->forceFill(['next_run_at' => now()->subDays(3)])->save();
        $dispatcher = app(MonitorDispatcher::class);

        $counts = $dispatcher->dispatch(CarbonImmutable::now('UTC'), false);
        $this->assertSame(1, $counts['dispatched']);
        $this->assertSame(3, $counts['missed']);
        $this->assertDatabaseCount('monitor_runs', 1);
        Bus::assertDispatched(RunScheduledMonitor::class, 1);

        $firstSlot = MonitorRun::query()->sole()->scheduled_for;
        $schedule->refresh()->forceFill(['next_run_at' => $firstSlot])->save();
        $dispatcher->dispatch(CarbonImmutable::now('UTC'), false);
        $this->assertDatabaseCount('monitor_runs', 1);

        $backfill = $this->schedule(User::factory()->create(), 'backfill.example');
        $backfill->forceFill(['next_run_at' => now()->subDays(5)])->save();
        config(['website_intelligence.monitoring.max_backfill_runs' => 3]);
        $result = $dispatcher->dispatch(CarbonImmutable::now('UTC'), true);
        $this->assertSame(3, $result['dispatched']);
        $this->assertSame(3, $backfill->runs()->count());
    }

    public function test_runs_build_versioned_evidence_material_comparisons_and_private_notifications(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner);
        $schedule->forceFill(['notification_preferences' => ['email_on_material_change' => true], 'thresholds' => ['minimum_changed_categories' => 3]])->save();
        $fetcher = new MutableMonitorFetcher('<h1>Plan</h1><p>Buy now for $10.</p>');
        $crawler = new BoundedSiteCrawler($fetcher, app(MonitoringEvidenceCollector::class));

        $this->executeRun($schedule, now(), $crawler);
        $fetcher->body = '<h1>Plan Plus</h1><p>Subscribe now for $25.</p>';
        $this->executeRun($schedule, now()->addDay(), $crawler);

        $comparison = MonitorComparison::query()->with(['previousVersion', 'currentVersion'])->sole();
        $this->assertTrue($comparison->material);
        $this->assertContains('content', array_column($comparison->source_changes, 'category'));
        $this->assertCount(2, $comparison->evidence_citations);
        $this->assertNotSame($comparison->evidence_citations[0]['version_id'], $comparison->evidence_citations[1]['version_id']);
        Notification::assertSentTo($owner, MaterialMonitorChange::class, function (MaterialMonitorChange $notification): bool {
            $encoded = json_encode($notification, JSON_THROW_ON_ERROR);

            return ! str_contains($encoded, 'https://') && ! str_contains($encoded, 'public.example') && ! str_contains($encoded, '$25');
        });
        $rawPage = $this->getConnection()->table('crawl_pages')->latest('id')->first();
        $this->assertStringNotContainsString('Plan Plus', $rawPage->evidence_snapshot);
        $this->assertStringNotContainsString('$25', $rawPage->evidence_snapshot);
        $this->assertStringNotContainsString('public.example', $rawPage->url);
    }

    public function test_parser_and_model_rollout_is_drift_not_a_source_fact(): void
    {
        Notification::fake();
        $schedule = $this->schedule(User::factory()->create());
        $fetcher = new MutableMonitorFetcher('<h1>Same evidence</h1><p>Contact us.</p>');
        $crawler = new BoundedSiteCrawler($fetcher, app(MonitoringEvidenceCollector::class));
        $this->executeRun($schedule, now(), $crawler);
        $schedule->forceFill(['parser_version' => 'monitor-parser-v2', 'model_version' => 'rules-v2'])->save();
        $this->executeRun($schedule, now()->addDay(), $crawler);

        $comparison = MonitorComparison::query()->sole();
        $this->assertFalse($comparison->material);
        $this->assertSame([], $comparison->source_changes);
        $this->assertSame(['parser_version', 'model_version'], array_column($comparison->version_drift, 'kind'));
        $this->assertSame([false, false], array_column($comparison->version_drift, 'source_fact'));
        Notification::assertNothingSent();
    }

    public function test_notification_failure_is_recorded_without_exposing_source_data(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner);
        $schedule->forceFill(['notification_preferences' => ['email_on_material_change' => true]])->save();
        $fetcher = new MutableMonitorFetcher('<h1>First</h1>');
        $crawler = new BoundedSiteCrawler($fetcher, app(MonitoringEvidenceCollector::class));
        $this->executeRun($schedule, now(), $crawler);
        $fetcher->body = '<h1>Second</h1><p>Buy now for $99.</p>';
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('notification unavailable');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('notification unavailable');
            }
        });
        $run = $this->executeRun($schedule, now()->addDay(), $crawler);

        $this->assertNotNull($run->notification_failed_at);
        $this->assertDatabaseMissing('monitor_runs', ['safe_failure_category' => 'notification unavailable']);
    }

    public function test_expiry_pause_moderation_budget_and_deletion_prevent_new_runs(): void
    {
        Bus::fake();
        CarbonImmutable::setTestNow('2026-11-03T12:00:00Z');
        $dispatcher = app(MonitorDispatcher::class);

        $expired = $this->schedule(User::factory()->create(), 'expired.example');
        $expired->verification->forceFill(['verified_until' => now()->subSecond()])->save();
        $dispatcher->dispatch(CarbonImmutable::now('UTC'));
        $this->assertSame('paused', $expired->refresh()->state);

        $blocked = $this->schedule(User::factory()->create(), 'blocked.example');
        ModerationDomainBlock::create(['domain_hash' => $blocked->verification->domain_hash, 'category' => 'reported_abuse', 'created_by' => $blocked->owner_id, 'blocked_at' => now()]);
        $dispatcher->dispatch(CarbonImmutable::now('UTC'));
        $this->assertSame('paused', $blocked->refresh()->state);

        $budget = $this->schedule(User::factory()->create(), 'budget.example');
        $budget->forceFill(['monthly_run_budget' => 1])->save();
        MonitorRun::create(['monitor_schedule_id' => $budget->id, 'public_id' => 'run_'.Str::ulid(), 'state' => 'completed', 'scheduled_for' => now()]);
        $dispatcher->dispatch(CarbonImmutable::now('UTC'));
        $this->assertSame('paused', $budget->refresh()->state);

        $paused = $this->schedule(User::factory()->create(), 'paused.example');
        $paused->forceFill(['state' => 'paused', 'paused_at' => now()])->save();
        $deleted = $this->schedule(User::factory()->create(), 'deleted.example');
        $deleted->forceFill(['state' => 'deleted', 'deleted_at' => now()])->save();
        $dispatcher->dispatch(CarbonImmutable::now('UTC'));
        $this->assertSame(0, $paused->runs()->count() + $deleted->runs()->count());
    }

    public function test_owner_controls_cancel_retry_pause_resume_and_delete_without_foreign_access(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $schedule = $this->schedule($owner);
        $run = MonitorRun::create(['monitor_schedule_id' => $schedule->id, 'public_id' => 'run_'.Str::ulid(), 'state' => 'queued', 'scheduled_for' => now()]);

        $this->actingAs($other)->post('/account/monitoring/runs/'.$run->public_id.'/control', ['action' => 'cancel'])->assertNotFound();
        $this->actingAs($owner)->post('/account/monitoring/'.$schedule->public_id.'/control', ['action' => 'pause'])->assertRedirect();
        $this->assertSame('paused', $schedule->refresh()->state);
        $this->assertSame('cancelled', $run->refresh()->state);
        $this->actingAs($owner)->post('/account/monitoring/'.$schedule->public_id.'/control', ['action' => 'resume'])->assertRedirect();
        $this->assertSame('active', $schedule->refresh()->state);

        $run->forceFill(['state' => 'failed'])->save();
        $this->actingAs($owner)->post('/account/monitoring/runs/'.$run->public_id.'/control', ['action' => 'retry'])->assertRedirect();
        $this->assertSame('queued', $run->refresh()->state);
        Bus::assertDispatched(RunScheduledMonitor::class);
        $this->actingAs($owner)->post('/account/monitoring/'.$schedule->public_id.'/control', ['action' => 'delete'])->assertRedirect();
        $this->assertSame('deleted', $schedule->refresh()->state);
    }

    public function test_scheduler_has_overlap_and_single_server_guards_and_page_is_accessible(): void
    {
        $event = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command ?? '', 'intelligence:dispatch-monitors'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        $owner = User::factory()->create();
        $this->verified($owner);
        $this->actingAs($owner)->get('/account/monitoring')->assertOk()->assertSee('Skip to monitoring controls')->assertSee('parser or model drift is labelled separately');
        $this->actingAs(User::factory()->unverified()->create())->get('/account/monitoring')->assertRedirect('/verify-email');
    }

    private function executeRun(MonitorSchedule $schedule, $scheduledFor, BoundedSiteCrawler $crawler): MonitorRun
    {
        $run = MonitorRun::create(['monitor_schedule_id' => $schedule->id, 'public_id' => 'run_'.Str::ulid(), 'state' => 'queued', 'scheduled_for' => $scheduledFor]);
        (new RunScheduledMonitor($run->id))->handle($crawler, app(MonitorComparisonService::class), app(MonitoringNotifier::class));

        return $run->refresh();
    }

    private function schedule(User $owner, string $domain = 'public.example'): MonitorSchedule
    {
        return app(MonitorScheduleService::class)->create($owner, $this->verified($owner, $domain), 24, 31, 1, ['email_on_material_change' => false]);
    }

    private function verified(User $owner, string $domain = 'public.example'): DomainVerification
    {
        return DomainVerification::create([
            'owner_id' => $owner->id, 'public_id' => 'dom_'.Str::ulid(), 'domain' => $domain,
            'domain_hash' => hash_hmac('sha256', $domain, (string) config('app.key')), 'method' => 'dns_txt',
            'challenge_token' => 'token', 'challenge_token_hash' => hash('sha256', 'token'),
            'challenge_expires_at' => now()->addHour(), 'verified_at' => now(), 'verified_until' => now()->addMonth(),
        ]);
    }
}

class MutableMonitorFetcher implements CrawlPageFetcher
{
    public function __construct(public string $body) {}

    public function fetch(string $url): CrawlFetchResult
    {
        return str_ends_with($url, '/robots.txt')
            ? new CrawlFetchResult(200, 'text/plain', "User-agent: *\nAllow: /")
            : new CrawlFetchResult(200, 'text/html', $this->body);
    }
}
