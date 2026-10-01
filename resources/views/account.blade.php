<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Account - Shortly</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased dark:bg-slate-950 dark:text-white">
        <main class="mx-auto flex min-h-screen w-full max-w-3xl flex-col px-5 py-10 sm:px-8">
            <nav class="flex items-center justify-between gap-4" aria-label="Account navigation">
                <a href="{{ route('home') }}" class="text-lg font-bold">Shortly</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:bg-white dark:text-slate-950">
                        Log out
                    </button>
                </form>
            </nav>

            <section class="mt-16 max-w-xl">
                <p class="text-sm font-medium text-blue-600 dark:text-blue-300">Signed in</p>
                <h1 class="mt-3 text-3xl font-bold tracking-tight">Your account</h1>
                <p class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-300">
                    You are signed in as {{ auth()->user()->email }}.
                </p>
                @if (session('status') === 'email-verified')
                    <p class="mt-5 rounded-lg bg-green-100 px-4 py-3 text-sm font-medium text-green-900" role="status">Your email address has been verified.</p>
                @elseif (! auth()->user()->hasVerifiedEmail())
                    <div class="mt-5 rounded-lg bg-amber-100 px-4 py-3 text-sm text-amber-950" role="status">
                        Your email address is not verified. You can continue using safe account pages, but ownership-sensitive features require verification.
                        <a href="{{ route('verification.notice') }}" class="font-semibold underline">Verify your email</a>.
                    </div>
                @endif
                <a href="{{ route('home') }}" class="mt-8 inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-white/20">
                    Shorten a link
                </a>
            </section>
        </main>
    </body>
</html>
