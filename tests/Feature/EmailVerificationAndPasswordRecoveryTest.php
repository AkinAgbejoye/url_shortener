<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationAndPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'new-correct-horse-battery-staple';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registration_sends_an_offline_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect('/account');

        $user = User::firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_users_can_navigate_the_account_but_not_verified_only_routes(): void
    {
        Route::middleware(['web', 'auth', 'verified'])
            ->get('/test/verified-only', fn (): string => 'verified action');

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('Your email address is not verified')
            ->assertSee('Verify your email');

        $this->get('/test/verified-only')->assertRedirect('/verify-email');

        $user->markEmailAsVerified();
        $this->get('/test/verified-only')->assertOk()->assertSee('verified action');
    }

    public function test_a_signed_link_verifies_email_once_and_reports_success_accessibly(): void
    {
        Event::fake([Verified::class]);
        Carbon::setTestNow('2026-10-01 10:00:00');
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user, now()->addMinutes(60));

        $this->actingAs($user)->get($url)->assertRedirect('/account');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->get('/account')
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Your email address has been verified.');

        $this->get($url)->assertConflict();
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_expired_or_invalid_verification_links_are_rejected(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $user = User::factory()->unverified()->create();
        $expiredUrl = $this->verificationUrl($user, now()->addMinute());
        $wrongPurposeUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->getKey(), 'hash' => sha1('another@example.com')],
        );

        Carbon::setTestNow('2026-10-01 10:02:00');

        $this->actingAs($user)->get($expiredUrl)->assertForbidden();
        $this->get($wrongPurposeUrl)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_notice_and_resend_are_accessible_and_throttled(): void
    {
        Notification::fake();
        Cache::clear();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/verify-email')
            ->assertOk()
            ->assertSee('Verify your email')
            ->assertSee('name="_token"', false);

        foreach (range(1, 3) as $attempt) {
            $this->post('/email/verification-notification')
                ->assertRedirect()
                ->assertSessionHas('status', 'verification-link-sent');
        }

        $this->post('/email/verification-notification')->assertTooManyRequests();
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }

    public function test_password_reset_requests_have_the_same_public_shape_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $known = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => ' KNOWN@Example.COM ',
        ]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect('/forgot-password')
                ->assertSessionHas('status', 'If an account exists for that email, we have sent a password reset link.')
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_request_form_is_accessible_validated_and_throttled(): void
    {
        Notification::fake();
        Cache::clear();

        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('name="_token"', false);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'invalid'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors(['email' => 'Enter a valid email address.']);

        app(RateLimiter::class)->clear(hash('sha256', 'limited@example.com|127.0.0.1'));
        foreach (range(1, 5) as $attempt) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => 'limited@example.com'])
                ->assertRedirect('/forgot-password');
        }

        $this->post('/forgot-password', ['email' => 'limited@example.com'])->assertTooManyRequests();
    }

    public function test_a_password_can_be_reset_once_and_older_authentication_state_is_invalidated(): void
    {
        Notification::fake();
        config()->set('session.driver', 'database');
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'old-correct-horse-battery-staple',
            'remember_token' => 'old-remember-token',
        ]);
        $this->seedSessionFor($user, 'older-authenticated-session');

        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->resetTokenFor($user);
        $payload = [
            'token' => $token,
            'email' => ' OWNER@Example.COM ',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->post('/reset-password', $payload)
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your password has been reset. You can now log in.');

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'older-authenticated-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->from('/reset-password/'.$token)->post('/reset-password', $payload)
            ->assertRedirect('/reset-password/'.$token)
            ->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired.']);
    }

    public function test_expired_reset_tokens_and_invalid_passwords_are_rejected_without_flashing_secrets(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->resetTokenFor($user);

        $this->get('/reset-password/'.$token.'?email=owner%40example.com')
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('name="_token"', false);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertRedirect('/reset-password/'.$token)
            ->assertSessionHasErrors(['password' => 'The password confirmation does not match.'])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.token');

        Carbon::setTestNow('2026-10-01 11:01:00');

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/reset-password/'.$token)
            ->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired.']);

        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    private function verificationUrl(User $user, Carbon $expiresAt): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expiresAt,
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    private function resetTokenFor(User $user): string
    {
        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }

    private function seedSessionFor(User $user, string $id): void
    {
        $this->getConnection()->table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }
}
