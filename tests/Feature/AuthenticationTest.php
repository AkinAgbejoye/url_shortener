<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_visitor_can_register_refresh_an_authenticated_page_and_log_out(): void
    {
        Log::spy();
        $beforeLoginSession = session()->getId();

        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => '  ADA@Example.COM ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/account');

        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertNotSame($beforeLoginSession, session()->getId());

        $this->get('/account')
            ->assertOk()
            ->assertSee('Your account')
            ->assertSee('ada@example.com')
            ->assertDontSee(self::PASSWORD);

        $beforeLogoutSession = session()->getId();
        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertNotSame($beforeLogoutSession, session()->getId());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_visitor_can_log_in_and_the_session_identifier_rotates(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => self::PASSWORD,
        ]);
        $beforeLoginSession = session()->getId();

        $this->post('/login', [
            'email' => ' OWNER@Example.COM ',
            'password' => self::PASSWORD,
        ])->assertRedirect('/account');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($beforeLoginSession, session()->getId());
        $this->get('/account')->assertOk()->assertSee('owner@example.com');
    }

    public function test_registration_validation_uses_stable_messages_and_does_not_flash_passwords(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'A',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertRedirect('/register')
            ->assertSessionHasErrors([
                'name' => 'Your name must be at least 2 characters.',
                'email' => 'Enter a valid email address.',
                'password' => 'The password confirmation does not match.',
            ])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_registration_email_is_normalized_and_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->from('/register')->post('/register', [
            'name' => 'Grace Hopper',
            'email' => ' TAKEN@example.COM ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect('/register')
            ->assertSessionHasErrors([
                'email' => 'This email address is already registered.',
            ]);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_login_credentials_are_enumeration_resistant(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => self::PASSWORD,
        ]);

        $knownUserResponse = $this->from('/login')->post('/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ]);
        $unknownUserResponse = $this->from('/login')->post('/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ]);

        foreach ([$knownUserResponse, $unknownUserResponse] as $response) {
            $response->assertRedirect('/login')
                ->assertSessionHasErrors([
                    'email' => 'These credentials do not match our records.',
                ])
                ->assertSessionMissing('_old_input.password');
        }

        $this->assertGuest();
    }

    public function test_registration_and_login_are_rate_limited(): void
    {
        Cache::clear();
        app(RateLimiter::class)->clear(hash('sha256', 'limited@example.com|127.0.0.1'));

        foreach (range(1, 3) as $attempt) {
            $this->from('/register')->post('/register', [
                'name' => 'Limited User',
                'email' => 'limited@example.com',
                'password' => 'too-short',
                'password_confirmation' => 'different',
            ])->assertRedirect('/register');
        }

        $this->from('/register')->post('/register', [
            'name' => 'Limited User',
            'email' => 'limited@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertTooManyRequests();

        Cache::clear();
        app(RateLimiter::class)->clear(hash('sha256', 'limited-login@example.com|127.0.0.1'));

        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', [
                'email' => 'limited-login@example.com',
                'password' => 'wrong-password',
            ])->assertRedirect('/login');
        }

        $this->from('/login')->post('/login', [
            'email' => 'limited-login@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_authentication_forms_include_csrf_tokens(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="_token"', false);

        $this->get('/register')
            ->assertOk()
            ->assertSee('name="_token"', false);
    }

    public function test_authenticated_users_are_redirected_away_from_guest_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/account');
        $this->actingAs($user)->get('/register')->assertRedirect('/account');
    }

    public function test_homepage_navigation_reflects_guest_and_authenticated_sessions(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Log in')
            ->assertSee('Create account')
            ->assertDontSee('Log out');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Account')
            ->assertSee('Log out')
            ->assertDontSee('Create account');
    }

    public function test_public_shortening_and_redirects_remain_available_to_guests(): void
    {
        Cache::clear();

        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://guest.example/path',
        ])->assertCreated();

        $this->assertGuest();
        $this->assertDatabaseHas('urls', [
            'short_code' => $response->json('short_code'),
            'long_url' => 'https://guest.example/path',
            'owner_id' => null,
        ]);

        $this->get('/'.$response->json('short_code'))
            ->assertRedirect('https://guest.example/path');
    }
}
