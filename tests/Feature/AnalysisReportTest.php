<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisJobService;
use App\Services\AnalysisReportBuilder;
use App\Services\AnalysisReportPresenter;
use App\Services\ApiKeyService;
use App\Services\SafePageExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalysisReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_is_typed_traceable_and_matches_the_openapi_shape(): void
    {
        $owner = User::factory()->create();
        $job = $this->completedReport($owner);
        $data = app(AnalysisReportPresenter::class)->present($job);

        $this->assertSame(['analysis_id', 'schema_version', 'generated_at', 'evidence', 'observations', 'inferences', 'assumptions', 'user_supplied_context'], array_keys($data));
        $this->assertSame('1.0', $data['schema_version']);
        $this->assertNotEmpty($data['observations']);
        $this->assertCount(count($data['observations']), $data['evidence']);
        foreach ($data['observations'] as $index => $observation) {
            $this->assertSame(['id', 'statement', 'evidence_ids'], array_keys($observation));
            $this->assertSame(['id', 'source_url', 'captured_at', 'excerpt'], array_keys($data['evidence'][$index]));
            $this->assertSame([$data['evidence'][$index]['id']], $observation['evidence_ids']);
            $this->assertSame('https://shop.example/products/widget', $data['evidence'][$index]['source_url']);
        }
        $this->assertSame([], $data['inferences']);
        $this->assertSame([], $data['assumptions']);
        $this->assertSame('Independent maker audience', $data['user_supplied_context'][0]['statement']);
        $this->assertDatabaseCount('analysis_page_fetches', 1);
        $this->assertDatabaseCount('analysis_reports', 1);
        $this->assertGreaterThan(8, $job->observations()->count());

        $rawUrl = DB::table('analysis_page_fetches')->value('source_url');
        $this->assertIsString($rawUrl);
        $this->assertStringNotContainsString('shop.example', $rawUrl);
        $rawContext = DB::table('analysis_jobs')->where('id', $job->id)->value('user_context');
        $this->assertIsString($rawContext);
        $this->assertStringNotContainsString('Independent maker audience', $rawContext);
    }

    public function test_owner_can_read_export_and_render_a_script_free_accessible_report(): void
    {
        $owner = User::factory()->create();
        $job = $this->completedReport($owner);
        $token = app(ApiKeyService::class)->create($owner, 'report', ['analyses:read'], null)['plain_text_key'];

        $this->withToken($token)->getJson('/api/v1/analyses/'.$job->public_id.'/report')
            ->assertOk()
            ->assertJsonPath('analysis_id', $job->public_id)
            ->assertJsonPath('schema_version', '1.0')
            ->assertJsonCount(0, 'inferences');

        $page = $this->actingAs($owner)->get('/account/analyses/'.$job->public_id.'/report')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('Skip to report')
            ->assertSee('Source observations')
            ->assertSee('Source location')
            ->assertSee('Content hash')
            ->assertSee('Evidence excerpt')
            ->assertSee('Acme &lt;Store&gt;', false)
            ->assertDontSee('<script>window.steal', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('onclick=', false)
            ->assertDontSee('<img', false);
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));

        $this->actingAs($owner)->get('/account/analyses/'.$job->public_id.'/report.json')
            ->assertOk()
            ->assertDownload($job->public_id.'-report.json')
            ->assertJsonPath('analysis_id', $job->public_id);
    }

    public function test_missing_and_foreign_reports_have_constant_shapes(): void
    {
        $owner = User::factory()->create();
        $foreign = $this->completedReport(User::factory()->create());
        $token = app(ApiKeyService::class)->create($owner, 'report', ['analyses:read'], null)['plain_text_key'];
        $missingId = 'ana_'.Str::ulid();

        $foreignApi = $this->withToken($token)->getJson('/api/v1/analyses/'.$foreign->public_id.'/report')->assertNotFound();
        $missingApi = $this->withToken($token)->getJson('/api/v1/analyses/'.$missingId.'/report')->assertNotFound();
        $this->assertSame($foreignApi->json('error.code'), $missingApi->json('error.code'));
        $this->assertSame($foreignApi->json('error.message'), $missingApi->json('error.message'));

        $foreignHtml = $this->actingAs($owner)->get('/account/analyses/'.$foreign->public_id.'/report')->assertNotFound();
        $missingHtml = $this->actingAs($owner)->get('/account/analyses/'.$missingId.'/report')->assertNotFound();
        $this->assertSame($foreignHtml->getContent(), $missingHtml->getContent());
    }

    public function test_pending_expired_and_unverified_report_boundaries_are_stable(): void
    {
        $owner = User::factory()->create();
        $pending = $this->job($owner, AnalysisState::Analyzing);
        $expired = $this->completedReport($owner);
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        $token = app(ApiKeyService::class)->create($owner, 'report', ['analyses:read'], null)['plain_text_key'];

        $this->withToken($token)->getJson('/api/v1/analyses/'.$pending->public_id.'/report')
            ->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->withToken($token)->getJson('/api/v1/analyses/'.$expired->public_id.'/report')
            ->assertStatus(410)->assertJsonPath('error.code', 'expired');
        $this->actingAs($owner)->get('/account/analyses/'.$expired->public_id.'/report')
            ->assertStatus(410)->assertSee('Report expired');

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get('/account/analyses/'.$pending->public_id.'/report')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_offline_browser_fixture_command_creates_a_completed_report(): void
    {
        $owner = User::factory()->create(['email' => 'browser-report@example.test']);

        $this->artisan('e2e:analysis-report', ['email' => $owner->email])->assertSuccessful();

        $job = AnalysisJob::query()->where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame(AnalysisState::Completed, $job->state);
        $this->assertNotNull($job->report);
        $this->assertGreaterThan(0, $job->observations()->count());
    }

    public function test_repeat_analysis_uses_idempotency_but_allows_a_new_completed_run(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $service = app(AnalysisJobService::class);
        $first = $service->create($owner, 'https://repeat.example/', str_repeat('a', 20), [], 'repeat-analysis-0001');
        $first['job']->forceFill(['state' => AnalysisState::Completed, 'completed_at' => now()])->save();

        $replay = $service->create($owner, 'https://repeat.example/', str_repeat('a', 20), [], 'repeat-analysis-0001');
        $second = $service->create($owner, 'https://repeat.example/', str_repeat('a', 20), [], 'repeat-analysis-0002');

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['job']->id, $replay['job']->id);
        $this->assertNotSame($first['job']->id, $second['job']->id);
        $this->assertDatabaseCount('analysis_jobs', 2);
    }

    private function completedReport(User $owner): AnalysisJob
    {
        $job = $this->job($owner, AnalysisState::Analyzing);
        $html = file_get_contents(base_path('tests/Fixtures/intelligence/shop-page.html'));
        $this->assertIsString($html);
        $result = (new SafePageExtractor)->extract($html, 'text/html', null, 'https://shop.example/products/widget', 200);
        app(AnalysisReportBuilder::class)->build($job, $result);
        $job->forceFill(['state' => AnalysisState::Completed, 'completed_at' => now(), 'extraction' => $result['extraction'], 'provenance' => $result['provenance']])->save();

        return $job->refresh();
    }

    private function job(User $owner, AnalysisState $state): AnalysisJob
    {
        return AnalysisJob::create([
            'owner_id' => $owner->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://shop.example/products/widget',
            'submitted_origin' => 'https://shop.example',
            'origin_hash' => hash('sha256', 'https://shop.example'),
            'domain_hash' => hash('sha256', 'shop.example'),
            'state' => $state,
            'user_context' => ['Independent maker audience'],
            'expires_at' => now()->addDay(),
        ]);
    }
}
