<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\URL;

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
    'urls:prune-analytics --batch-size=%d --retention-days=%d',
    config('url_shortener.analytics.cleanup.batch_size'),
    config('url_shortener.analytics.retention_days'),
))
    ->dailyAt(config('url_shortener.analytics.cleanup.time'))
    ->withoutOverlapping(60)
    ->onOneServer();
