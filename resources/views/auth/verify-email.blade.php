<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Verify email - Shortly</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased dark:bg-slate-950 dark:text-white">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-5 py-10">
            <a href="{{ route('account') }}" class="mb-10 text-lg font-bold">Shortly</a>
            <h1 class="text-3xl font-bold tracking-tight">Verify your email</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
                We sent a verification link to {{ auth()->user()->email }}. Verify your address before using ownership-sensitive account features.
            </p>

            @if (session('status') === 'verification-link-sent')
                <p class="mt-5 rounded-lg bg-green-100 px-4 py-3 text-sm font-medium text-green-900" role="status">
                    A new verification link has been sent.
                </p>
            @endif

            <form method="POST" action="{{ route('verification.send') }}" class="mt-8">
                @csrf
                <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500">
                    Resend verification email
                </button>
            </form>
        </main>
    </body>
</html>
