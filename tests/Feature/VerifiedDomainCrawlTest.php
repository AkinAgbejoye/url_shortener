<?php

namespace Tests\Feature;

use App\Contracts\CrawlPageFetcher;
use App\Contracts\DomainControlVerifier;
use App\Data\CrawlFetchResult;
use App\Jobs\CrawlVerifiedDomain;
use App\Models\CrawlJob;
use App\Models\DomainVerification;
use App\Models\ModerationDomainBlock;
use App\Models\User;
use App\Services\BoundedSiteCrawler;
use App\Services\CrawlJobService;
use App\Services\DomainVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class VerifiedDomainCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_challenges_expire_confirm_revoke_and_remain_owner_scoped(): void
    {
        CarbonImmutable::setTestNow('2026-11-03T12:00:00Z');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->app->instance(DomainControlVerifier::class, new class implements DomainControlVerifier
        {
            public function verify(string $domain, string $method, string $token): bool
            {
                return $domain === 'public.example' && str_starts_with($token, 'shortly-verification=');
            }
        });
        $service = app(DomainVerificationService::class);
        $this->actingAs($owner)->get('/account/domains')->assertOk()->assertSee('Verified-domain crawling')->assertSee('Skip to domain controls');
        $issued = $service->challenge($owner, 'Public.Example.', 'dns_txt');
        $this->assertStringStartsWith('shortly-verification=', $issued['token']);
        $this->assertTrue($service->confirm($issued['verification']));
        $this->assertTrue($issued['verification']->refresh()->current());

        $this->actingAs($other)->post('/account/domains/'.$issued['verification']->public_id.'/confirmation')->assertNotFound();
        $this->actingAs($owner)->delete('/account/domains/'.$issued['verification']->public_id)->assertRedirect();
        $this->assertFalse($issued['verification']->refresh()->current());

        $expired = $service->challenge($owner, 'expired.example', 'well_known')['verification'];
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        $this->assertFalse($service->confirm($expired));
    }

    public function test_bounded_crawler_honors_robots_same_origin_traps_canonical_and_budgets(): void
    {
        $owner = User::factory()->create();
        $verification = $this->verified($owner, 'public.example');
        $job = $this->crawl($owner, $verification, ['include_paths' => ['/'], 'exclude_paths' => ['/excluded'], 'max_pages' => 10, 'max_depth' => 2, 'max_bytes' => 100000]);
        $fetcher = new class implements CrawlPageFetcher
        {
            public array $fetched = [];

            public function fetch(string $url): CrawlFetchResult
            {
                $this->fetched[] = $url;

                return match ($url) {
                    'https://public.example/robots.txt' => new CrawlFetchResult(200, 'text/plain', "User-agent: *\nDisallow: /private\nAllow: /private/public"),
                    'https://public.example/' => new CrawlFetchResult(200, 'text/html', '<link rel="canonical" href="/"><a href="/about">About</a><a href="/copy">Copy</a><a href="/private">Private</a><a href="/2026/01/">Trap</a><a href="https://other.example/">Other</a>'),
                    'https://public.example/about' => new CrawlFetchResult(200, 'text/html', '<link rel="canonical" href="/about"><a href="/?session=x">Session</a>'),
                    'https://public.example/copy' => new CrawlFetchResult(200, 'text/html', '<link rel="canonical" href="/about"><p>Duplicate canonical.</p>'),
                    default => throw new RuntimeException('Unexpected fetch '.$url),
                };
            }
        };
        $crawler = new BoundedSiteCrawler($fetcher);
        $summary = $crawler->crawl($job);

        $this->assertSame(3, $summary['fetched']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(1, $summary['deduplicated']);
        $this->assertCount(4, $fetcher->fetched);
        $this->assertDatabaseCount('crawl_pages', 3);
        $this->assertDatabaseHas('crawl_pages', ['crawl_job_id' => $job->id, 'outcome' => 'duplicate']);
        $this->assertSame('RFC 9309', $job->refresh()->robots_policy['standard']);
    }

    public function test_only_current_unblocked_verification_can_queue_and_controls_are_owner_scoped(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $verification = $this->verified($owner, 'queue.example');
        $job = app(CrawlJobService::class)->create($owner, $verification, ['max_pages' => 9999, 'max_depth' => 99]);
        $this->assertSame((int) config('website_intelligence.crawling.max_pages'), $job->policy['max_pages']);
        $this->assertSame((int) config('website_intelligence.crawling.max_depth'), $job->policy['max_depth']);
        Bus::assertDispatched(CrawlVerifiedDomain::class);
        $this->actingAs($other)->post('/account/domains/crawls/'.$job->public_id.'/control', ['action' => 'cancel'])->assertNotFound();
        $this->actingAs($owner)->post('/account/domains/crawls/'.$job->public_id.'/control', ['action' => 'pause'])->assertRedirect();
        $this->assertSame('paused', $job->refresh()->state);

        ModerationDomainBlock::create(['domain_hash' => $verification->domain_hash, 'category' => 'reported_abuse', 'created_by' => $owner->id, 'blocked_at' => now()]);
        $job->forceFill(['state' => 'completed', 'completed_at' => now()])->save();
        $this->expectException(RuntimeException::class);
        app(CrawlJobService::class)->create($owner, $verification, []);
    }

    public function test_worker_stops_when_verification_expires_or_owner_cancels(): void
    {
        $owner = User::factory()->create();
        $verification = $this->verified($owner, 'expiry.example');
        $job = $this->crawl($owner, $verification, ['include_paths' => ['/'], 'exclude_paths' => [], 'max_pages' => 2, 'max_depth' => 1, 'max_bytes' => 10000]);
        $verification->forceFill(['verified_until' => now()->subSecond()])->save();
        $fetcher = new class implements CrawlPageFetcher
        {
            public function fetch(string $url): CrawlFetchResult
            {
                return new CrawlFetchResult(200, 'text/plain', 'User-agent: *');
            }
        };
        (new CrawlVerifiedDomain($job->id))->handle(new BoundedSiteCrawler($fetcher));
        $this->assertSame('failed', $job->refresh()->state);
        $this->assertSame('authorization_expired', $job->summary['safe_failure_category']);

        $cancelled = $this->crawl($owner, $verification, ['include_paths' => ['/'], 'exclude_paths' => [], 'max_pages' => 1, 'max_depth' => 0, 'max_bytes' => 10000]);
        $cancelled->forceFill(['cancel_requested_at' => now()])->save();
        (new CrawlVerifiedDomain($cancelled->id))->handle(new BoundedSiteCrawler($fetcher));
        $this->assertSame('cancelled', $cancelled->refresh()->state);
    }

    private function verified(User $owner, string $domain): DomainVerification
    {
        $secret = (string) config('app.key');

        return DomainVerification::create([
            'owner_id' => $owner->id, 'public_id' => 'dom_'.Str::ulid(), 'domain' => $domain,
            'domain_hash' => hash_hmac('sha256', $domain, $secret), 'method' => 'dns_txt',
            'challenge_token' => 'token', 'challenge_token_hash' => hash_hmac('sha256', 'token', $secret),
            'challenge_expires_at' => now()->addHour(), 'verified_at' => now(), 'verified_until' => now()->addDay(),
        ]);
    }

    /** @param array<string, mixed> $policy */
    private function crawl(User $owner, DomainVerification $verification, array $policy): CrawlJob
    {
        return CrawlJob::create(['owner_id' => $owner->id, 'domain_verification_id' => $verification->id, 'public_id' => 'crawl_'.Str::ulid(), 'state' => 'queued', 'policy' => $policy, 'expires_at' => now()->addDay()]);
    }
}
