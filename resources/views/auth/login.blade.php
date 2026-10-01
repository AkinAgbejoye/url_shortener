<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Log in - Shortly</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased dark:bg-slate-950 dark:text-white">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-5 py-10">
            <a href="{{ route('home') }}" class="mb-10 text-lg font-bold">Shortly</a>
            <h1 class="text-3xl font-bold tracking-tight">Log in</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">Use your account to keep your links organized.</p>

            @if (session('status'))
                <p class="mt-5 rounded-lg bg-green-100 px-4 py-3 text-sm font-medium text-green-900" role="status">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5" novalidate>
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required aria-describedby="email-error" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-slate-950 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30">
                    @error('email')
                        <p id="email-error" class="mt-2 text-sm font-medium text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required aria-describedby="password-error" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-slate-950 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30">
                    @error('password')
                        <p id="password-error" class="mt-2 text-sm font-medium text-red-600" role="alert">{{ $message }}</p>
                    @enderror
                    <a href="{{ route('password.request') }}" class="mt-3 inline-block text-sm font-semibold text-blue-600 dark:text-blue-300">Forgot your password?</a>
                </div>
                <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500">
                    Log in
                </button>
            </form>

            <p class="mt-6 text-sm text-slate-600 dark:text-slate-300">
                No account yet?
                <a href="{{ route('register') }}" class="font-semibold text-blue-600 dark:text-blue-300">Create one</a>.
            </p>
        </main>
    </body>
</html>
