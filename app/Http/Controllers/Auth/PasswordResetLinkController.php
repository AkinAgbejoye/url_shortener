<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            [
                'email.required' => 'Enter your email address.',
                'email.email' => 'Enter a valid email address.',
            ],
        );

        Password::sendResetLink($credentials);

        return back()->with('status', 'If an account exists for that email, we have sent a password reset link.');
    }
}
