<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'email' => $request->query('email'),
            'token' => $token,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(
            [
                'token' => ['required', 'string'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'confirmed', PasswordRule::min(12), 'max:255'],
            ],
            [
                'email.required' => 'Enter your email address.',
                'email.email' => 'Enter a valid email address.',
                'password.required' => 'Enter a password.',
                'password.confirmed' => 'The password confirmation does not match.',
                'password.min' => 'The password must be at least 12 characters.',
                'password.max' => 'The password may not be greater than 255 characters.',
            ],
        );

        $status = Password::reset(
            $credentials,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table((string) config('session.table', 'sessions'))
                        ->where('user_id', $user->getAuthIdentifier())
                        ->delete();
                }

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PasswordReset) {
            return redirect()->route('login')->with('status', 'Your password has been reset. You can now log in.');
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => 'This password reset link is invalid or has expired.',
        ]);
    }
}
