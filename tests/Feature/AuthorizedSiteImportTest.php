<?php

namespace Tests\Feature;

use App\Contracts\CrawlPageFetcher;
use App\Data\CrawlFetchResult;
use App\Jobs\ImportVerifiedSite;
use App\Models\DomainVerification;
use App\Models\SiteImport;
use App\Models\User;
use App\Services\AnalysisRetentionPruner;
use App\Services\AuthorizedSiteImporter;
use App\Services\ImportAssetScanner;
use App\Services\ImportHtmlSanitizer;
use App\Services\SiteImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuthorizedSiteImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_verified_owner_can_create_an_encrypted_rights_bounded_import(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $verification = $this->verified($owner);
        $attestation = 'I hold the reproduction rights needed to create this private editable preview.';

        $import = app(SiteImportService::class)->create($owner, $verification, $attestation, ['Owned content', 'Licensed photography']);

        $this->assertSame('queued', $import->state);
        $this->assertTrue($import->rights_expires_at->lessThanOrEqualTo($verification->verified_until));
        $raw = $this->getConnection()->table('site_imports')->where('id', $import->id)->first();
        $this->assertStringNotContainsString('reproduction rights', $raw->rights_attestation);
        $this->assertStringNotContainsString('Licensed photography', $raw->declared_licenses);
        Bus::assertDispatched(ImportVerifiedSite::class);
    }

    public function test_import_strips_active_content_rewrites_links_and_rejects_confused_assets(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $import = app(SiteImportService::class)->create($owner, $this->verified($owner), $this->attestation(), ['Owned content']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $fetcher = new class($png) implements CrawlPageFetcher
        {
            public function __construct(private readonly string $png) {}

            public function fetch(string $url): CrawlFetchResult
            {
                return match ($url) {
                    'https://public.example/' => new CrawlFetchResult(200, 'text/html', '<!doctype html><html><head><title>Editable</title><link rel="stylesheet" href="/site.css"><script src="/evil.js"></script></head><body onload="steal()"><h1>Safe title</h1><form action="https://evil.test"><input type="password"></form><a href="https://evil.test" ping="https://tracker.test">Leave</a><img src="/good.png"><img src="/bad.jpg"><img src="/../escape.png"><img src="https://tracker.test/pixel.png" width="1"></body></html>'),
                    'https://public.example/site.css' => new CrawlFetchResult(200, 'text/css', 'body{color:#123;background:url(https://tracker.test/x)} @import "https://evil.test/x";'),
                    'https://public.example/good.png' => new CrawlFetchResult(200, 'image/png', $this->png),
                    'https://public.example/bad.jpg' => new CrawlFetchResult(200, 'image/jpeg', '<html><script>bad</script></html>'),
                    default => throw new RuntimeException('Unexpected fetch '.$url),
                };
            }
        };

        (new ImportVerifiedSite($import->id))->handle(new AuthorizedSiteImporter($fetcher, app(ImportHtmlSanitizer::class), app(ImportAssetScanner::class)));

        $import->refresh();
        $this->assertSame('partially_ready', $import->state);
        $this->assertSame(2, $import->sanitation_summary['accepted_assets']);
        $this->assertSame(1, $import->sanitation_summary['rejected_assets']);
        $this->assertDatabaseCount('site_import_assets', 2);
        foreach (['<script', '<form', '<input', 'onload=', 'evil.test', 'tracker.test', '../', '@import', 'url('] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, strtolower($import->sanitized_html));
        }
        $this->assertStringContainsString('data:image/png;base64,', $import->sanitized_html);
        $this->assertStringContainsString('href="#"', $import->sanitized_html);
        $this->assertSame('Safe title', $import->editable_representation['headings'][0]);
        $this->assertSame(2, count($import->provenance['asset_content_hashes']));
        $this->assertStringNotContainsString('Safe title', (string) $this->getConnection()->table('site_imports')->where('id', $import->id)->value('sanitized_html'));
    }

    public function test_preview_is_owner_only_current_sandboxed_private_and_non_publishing(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $import = $this->readyImport($owner);

        $response = $this->actingAs($owner)->get('/account/site-imports/'.$import->public_id.'/preview')
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('sandbox')->assertSee('Read-only sandboxed preview');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->actingAs($other)->get('/account/site-imports/'.$import->public_id.'/preview')->assertNotFound();

        $import->forceFill(['rights_expires_at' => now()->subSecond()])->save();
        $this->actingAs($owner)->get('/account/site-imports/'.$import->public_id.'/preview')->assertNotFound();
        $this->assertFalse((bool) config('website_intelligence.imports.publishing_enabled'));
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route): bool => str_contains($route->uri(), 'publish')));
    }

    public function test_expired_rights_cancellation_and_foreign_verification_create_no_fetch_work(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->verified($other);

        $this->expectException(RuntimeException::class);
        app(SiteImportService::class)->create($owner, $foreign, $this->attestation(), ['Owned']);
    }

    public function test_worker_rechecks_rights_and_cancellation_before_fetching(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $service = app(SiteImportService::class);
        $expired = $service->create($owner, $this->verified($owner), $this->attestation(), ['Owned']);
        $expired->forceFill(['rights_expires_at' => now()->subSecond()])->save();
        $neverFetches = new class implements CrawlPageFetcher
        {
            public function fetch(string $url): CrawlFetchResult
            {
                throw new RuntimeException('fetch must not run');
            }
        };
        $importer = new AuthorizedSiteImporter($neverFetches, app(ImportHtmlSanitizer::class), app(ImportAssetScanner::class));
        (new ImportVerifiedSite($expired->id))->handle($importer);
        $this->assertSame('failed', $expired->refresh()->state);
        $this->assertSame('authorization_expired', $expired->safe_failure_category);

        $expired->forceFill(['state' => 'completed'])->save();
        $cancelled = $service->create($owner, $this->verified($owner, 'second.example'), $this->attestation(), ['Owned']);
        $cancelled->forceFill(['cancel_requested_at' => now()])->save();
        (new ImportVerifiedSite($cancelled->id))->handle($importer);
        $this->assertSame('cancelled', $cancelled->refresh()->state);
    }

    public function test_confirmation_takedown_and_deletion_preserve_safe_lifecycle_metadata(): void
    {
        $owner = User::factory()->create();
        $import = $this->readyImport($owner);

        $this->actingAs($owner)->post('/account/site-imports/'.$import->public_id.'/control', ['action' => 'confirm'])->assertRedirect();
        $this->assertSame('confirmed', $import->refresh()->state);
        $this->assertNotNull($import->confirmed_at);
        $this->actingAs($owner)->post('/account/site-imports/'.$import->public_id.'/control', ['action' => 'takedown'])->assertRedirect();
        $this->assertSame('taken_down', $import->refresh()->state);
        $this->assertNull($import->sanitized_html);
        $this->assertNotNull($import->provenance);

        $deleted = $this->readyImport($owner, 'delete.example');
        $this->actingAs($owner)->post('/account/site-imports/'.$deleted->public_id.'/control', ['action' => 'delete'])->assertRedirect();
        $this->assertSame('deleted', $deleted->refresh()->state);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->sanitized_html);
    }

    public function test_reauthorization_requires_a_fresh_same_domain_verification_and_new_attestation(): void
    {
        $owner = User::factory()->create();
        $import = $this->readyImport($owner);
        $import->verification->forceFill(['verified_until' => now()->subSecond()])->save();
        $fresh = $this->verified($owner);
        $renewed = 'I newly attest that I retain reproduction rights for this private preview.';

        $this->actingAs($owner)->post('/account/site-imports/'.$import->public_id.'/control', [
            'action' => 'reauthorize', 'verification_id' => $fresh->public_id, 'rights_attestation' => $renewed,
        ])->assertRedirect();

        $this->assertSame($fresh->id, $import->refresh()->domain_verification_id);
        $this->assertSame($renewed, $import->rights_attestation);
        $this->assertTrue($import->authorized());
    }

    public function test_asset_scanner_rejects_archives_polyglots_and_oversized_files(): void
    {
        $scanner = app(ImportAssetScanner::class);
        config(['website_intelligence.imports.max_asset_bytes' => 1024]);
        foreach ([
            ["PK\x03\x04archive", 'image/png', '/file.png'],
            ['<html><script>x</script></html>', 'image/jpeg', '/photo.jpg'],
            ['body{}', 'text/css', '/../escape.css'],
            [str_repeat('x', 1025), 'image/png', '/large.png'],
        ] as [$body, $type, $path]) {
            try {
                $scanner->scan($body, $type, $path);
                $this->fail('Unsafe asset was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_import_page_is_accessible_and_requires_verified_email(): void
    {
        $owner = User::factory()->create();
        $this->verified($owner);
        $this->actingAs($owner)->get('/account/site-imports')->assertOk()->assertSee('Skip to import controls')->assertSee('Publishing and hosting are disabled');
        $this->actingAs(User::factory()->unverified()->create())->get('/account/site-imports')->assertRedirect('/verify-email');
    }

    public function test_expired_import_metadata_is_removed_by_bounded_retention(): void
    {
        $import = $this->readyImport(User::factory()->create());
        $import->forceFill(['expires_at' => now()->subSecond()])->save();

        app(AnalysisRetentionPruner::class)->prune(CarbonImmutable::now('UTC'), 7, 100, false);

        $this->assertDatabaseMissing('site_imports', ['id' => $import->id]);
    }

    private function readyImport(User $owner, string $domain = 'public.example'): SiteImport
    {
        return SiteImport::create([
            'owner_id' => $owner->id, 'domain_verification_id' => $this->verified($owner, $domain)->id,
            'public_id' => 'import_'.Str::ulid(), 'state' => 'preview_ready', 'rights_attestation' => $this->attestation(),
            'declared_licenses' => ['Owned'], 'rights_attested_at' => now(), 'rights_expires_at' => now()->addDay(),
            'policy' => ['max_pages' => 1, 'max_assets' => 20, 'max_total_bytes' => 1000000],
            'sanitized_html' => '<html><body><h1>Safe preview</h1></body></html>',
            'editable_representation' => ['schema_version' => '1.0'],
            'sanitation_summary' => ['removed_elements' => 2, 'accepted_assets' => 0, 'rejected_assets' => 0],
            'provenance' => ['root_content_hash' => hash('sha256', 'safe')], 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);
    }

    private function verified(User $owner, string $domain = 'public.example'): DomainVerification
    {
        return DomainVerification::create([
            'owner_id' => $owner->id, 'public_id' => 'dom_'.Str::ulid(), 'domain' => $domain,
            'domain_hash' => hash_hmac('sha256', $domain, (string) config('app.key')), 'method' => 'dns_txt',
            'challenge_token' => 'token', 'challenge_token_hash' => hash('sha256', 'token'),
            'challenge_expires_at' => now()->addHour(), 'verified_at' => now(), 'verified_until' => now()->addDays(2),
        ]);
    }

    private function attestation(): string
    {
        return 'I hold the reproduction rights needed to create this private editable preview.';
    }
}
