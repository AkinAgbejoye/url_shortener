<?php

namespace Tests\Feature;

use App\Enums\AnalysisState;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Models\AbuseReport;
use App\Models\AnalysisJob;
use App\Models\ModerationAuditEvent;
use App\Models\User;
use App\Services\AnalysisJobService;
use App\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_authenticated_intake_is_bounded_private_and_enumeration_resistant(): void
    {
        $this->get('/report-abuse')->assertOk()->assertSee('Report harmful analysis activity')->assertSee('Do not include passwords');

        $payload = [
            'category' => 'phishing',
            'subject_reference' => 'ana_not_known',
            'description' => 'This page appears to imitate a sign-in form and request credentials.',
            'evidence_reference' => 'Screenshot retained by reporter; no credentials included.',
            'contact_consent' => '1',
            'reporter_email' => 'reporter@example.test',
        ];
        $unknown = $this->from('/report-abuse')->post('/report-abuse', $payload)->assertRedirect('/report-abuse');
        $known = $this->from('/report-abuse')->post('/report-abuse', [...$payload, 'subject_reference' => 'ana_'.Str::ulid()])->assertRedirect('/report-abuse');
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertDatabaseCount('abuse_reports', 2);

        $raw = DB::table('abuse_reports')->first();
        $this->assertNotSame($payload['description'], $raw->description);
        $this->assertStringNotContainsString('reporter@example.test', (string) $raw->reporter_email);
        $this->assertSame(64, strlen((string) $raw->subject_reference_hash));

        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/report-abuse', [...$payload, 'contact_consent' => '0', 'reporter_email' => null])->assertRedirect();
        $authenticated = AbuseReport::query()->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $authenticated->reporter_id);
        $this->assertNull($authenticated->reporter_email);
    }

    public function test_intake_validation_and_throttling_do_not_create_unbounded_reports(): void
    {
        config(['website_intelligence.moderation.reports_per_hour' => 2]);
        $this->post('/report-abuse', ['category' => 'invalid', 'description' => 'short'])->assertSessionHasErrors(['category', 'description']);
        $payload = ['category' => 'other', 'description' => str_repeat('bounded report ', 3)];
        $this->post('/report-abuse', $payload)->assertRedirect();
        $this->post('/report-abuse', $payload)->assertTooManyRequests();
        $this->assertDatabaseCount('abuse_reports', 1);
    }

    public function test_only_allowlisted_verified_operators_can_review_sensitive_reports(): void
    {
        $operator = User::factory()->create(['email' => 'operator@example.test']);
        $other = User::factory()->create();
        config(['website_intelligence.operators.emails' => [$operator->email]]);
        $report = app(ModerationService::class)->report(null, [
            'category' => 'privacy', 'description' => 'A sufficiently detailed private moderation report.',
        ])['report'];

        $this->actingAs($other)->get('/account/operations/moderation')->assertNotFound();
        $this->actingAs($operator)->get('/account/operations/moderation')
            ->assertOk()->assertSee('Abuse and moderation queue')->assertSee('sufficiently detailed private moderation report');
        app(ModerationService::class)->act($operator, $report, 'assign');
        $this->actingAs($operator)->get(route('account.operations.moderation.export', $report))
            ->assertOk()->assertDownload($report->public_id.'-incident.json')
            ->assertJsonPath('actions.0.action', 'assign')
            ->assertJsonMissingPath('reporter_email')->assertJsonMissingPath('description');

        app(ModerationService::class)->act($operator, $report, 'delete_report_data');
        $this->assertSame('[deleted]', $report->refresh()->description);
        $this->assertNull($report->reporter_email);
    }

    public function test_domain_block_suspension_release_and_audit_are_atomic(): void
    {
        Bus::fake();
        $operator = User::factory()->create(['email' => 'operator@example.test']);
        $owner = User::factory()->create();
        config(['website_intelligence.operators.emails' => [$operator->email]]);
        $job = $this->analysis($owner, 'blocked.example');
        $report = app(ModerationService::class)->report(null, [
            'category' => 'malware', 'description' => 'Malware behavior was observed in a bounded local fixture.',
        ])['report'];

        $this->actingAs($operator)->post(route('account.operations.moderation.action', $report), [
            'action' => 'block_domain', 'analysis_id' => $job->public_id,
        ])->assertRedirect();
        $this->assertSame(AnalysisState::CancelRequested, $job->refresh()->state);
        $this->assertDatabaseCount('moderation_domain_blocks', 1);
        $this->assertDatabaseHas('moderation_audit_events', ['action' => 'block_domain', 'subject_type' => 'analysis_job']);
        $this->expectException(AcquisitionPolicyViolation::class);
        app(AnalysisJobService::class)->create($owner, 'https://blocked.example/', str_repeat('a', 20), [], 'blocked-domain-0001');
    }

    public function test_release_restores_domain_work_and_account_suspension_stops_all_active_work(): void
    {
        Bus::fake();
        $operator = User::factory()->create(['email' => 'operator@example.test']);
        $owner = User::factory()->create();
        config(['website_intelligence.operators.emails' => [$operator->email]]);
        $job = $this->analysis($owner, 'release.example');
        $report = app(ModerationService::class)->report(null, [
            'category' => 'other', 'description' => 'A bounded report used to verify recovery and suspension.',
        ])['report'];
        $moderation = app(ModerationService::class);
        $moderation->act($operator, $report, 'block_domain', $job);
        $moderation->act($operator, $report, 'release_domain', $job);
        $job->forceFill(['state' => AnalysisState::Cancelled, 'completed_at' => now()])->save();
        $created = app(AnalysisJobService::class)->create($owner, 'https://release.example/', str_repeat('a', 20), [], 'released-domain-01');
        $this->assertSame(AnalysisState::Queued, $created['job']->state);

        $moderation->act($operator, $report, 'suspend_account', $created['job']);
        $this->assertNotNull($owner->refresh()->intelligence_suspended_at);
        $this->assertSame(AnalysisState::CancelRequested, $created['job']->refresh()->state);
        try {
            app(AnalysisJobService::class)->create($owner, 'https://different.example/', str_repeat('a', 20), [], 'suspended-owner-01');
            $this->fail('Suspended owner created intelligence work.');
        } catch (AcquisitionPolicyViolation) {
            $this->assertTrue(true);
        }

        $event = ModerationAuditEvent::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    private function analysis(User $owner, string $host): AnalysisJob
    {
        $secret = (string) config('app.key');

        return AnalysisJob::create([
            'owner_id' => $owner->id, 'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://'.$host.'/', 'submitted_origin' => 'https://'.$host,
            'origin_hash' => hash_hmac('sha256', 'https://'.$host, $secret),
            'domain_hash' => hash_hmac('sha256', $host, $secret),
            'state' => AnalysisState::Queued, 'expires_at' => now()->addDay(),
        ]);
    }
}
