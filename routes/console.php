<?php

use App\Enums\AnalysisState;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisReportBuilder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (app()->environment('testing')) {
    Artisan::command('e2e:account-link {purpose} {email}', function (): int {
        $purpose = (string) $this->argument('purpose');
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error('No matching test account.');

            return 1;
        }

        $url = match ($purpose) {
            'verify' => URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            ),
            'reset' => route('password.reset', [
                'token' => Password::broker()->createToken($user),
                'email' => $user->email,
            ]),
            default => null,
        };

        if (! is_string($url)) {
            $this->error('Purpose must be verify or reset.');

            return 1;
        }

        $this->line($url);

        return 0;
    })->purpose('Generate an offline account link for Playwright');

    Artisan::command('e2e:analysis-report {email}', function (): int {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            $this->error('No matching verified test account.');

            return 1;
        }
        $job = AnalysisJob::create([
            'owner_id' => $user->id,
            'public_id' => 'ana_'.Str::ulid(),
            'target_url' => 'https://fixture.example/product',
            'submitted_origin' => 'https://fixture.example',
            'origin_hash' => hash('sha256', 'fixture-origin-'.Str::random()),
            'domain_hash' => hash('sha256', 'fixture-domain-'.Str::random()),
            'state' => AnalysisState::Analyzing,
            'user_context' => ['Offline accessibility fixture'],
            'expires_at' => now()->addDay(),
        ]);
        $result = [
            'source_url' => 'https://fixture.example/product',
            'extraction' => [
                'identity' => ['title' => 'Accessible fixture report', 'description' => 'Offline evidence', 'canonical_url' => null],
                'heading_details' => [['level' => 'h1', 'text' => 'Fixture heading']],
                'text' => 'A deterministic evidence excerpt.', 'links' => [], 'structured_data' => [],
                'pricing_signals' => [], 'calls_to_action' => [], 'contact_methods' => [], 'technology_indicators' => [],
            ],
            'provenance' => [
                'fetched_at' => now()->toISOString(), 'status' => 200, 'content_type' => 'text/html',
                'content_hash' => hash('sha256', 'fixture'), 'compressed_bytes' => 7, 'decompressed_bytes' => 7,
            ],
        ];
        app(AnalysisReportBuilder::class)->build($job, $result);
        $job->forceFill(['state' => AnalysisState::Completed, 'completed_at' => now()])->save();
        $this->line(route('account.analyses.report', $job->public_id));

        return 0;
    })->purpose('Create an offline evidence report for Playwright');
}

Schedule::command(sprintf(
    'urls:prune-lifecycle --batch-size=%d --retention-days=%d',
    config('url_shortener.cleanup.batch_size'),
    config('url_shortener.cleanup.retention_days'),
))
    ->dailyAt(config('url_shortener.cleanup.time'))
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command(sprintf(
    'intelligence:prune-retention --batch-size=%d --metadata-days=%d',
    config('website_intelligence.reports.cleanup_batch_size'),
    config('website_intelligence.reports.expired_metadata_days'),
))
    ->dailyAt(config('website_intelligence.reports.cleanup_time'))
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command(sprintf(
    'urls:prune-analytics --batch-size=%d --retention-days=%d',
    config('url_shortener.analytics.cleanup.batch_size'),
    config('url_shortener.analytics.retention_days'),
))
    ->dailyAt(config('url_shortener.analytics.cleanup.time'))
    ->withoutOverlapping(60)
    ->onOneServer();
