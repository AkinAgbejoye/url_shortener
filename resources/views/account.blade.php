<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Your links - Shortly</title>
        <script>
            (() => {
                try {
                    const savedTheme = localStorage.getItem('shortly.theme');
                    const dark = savedTheme ? savedTheme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch (_) {
                    // Use the light theme when browser preferences are unavailable.
                }
            })();
        </script>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased dark:bg-slate-950 dark:text-white">
        <a href="#main-content" class="fixed left-4 top-4 z-50 -translate-y-24 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-950 shadow-lg transition focus:translate-y-0 focus:outline-2 focus:outline-offset-2 focus:outline-blue-500">Skip to main content</a>
        <header class="border-b border-slate-200 bg-white/80 dark:border-white/10 dark:bg-slate-950/80">
            <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-5 sm:px-8" aria-label="Account navigation">
                <a href="{{ route('home') }}" class="text-xl font-bold focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-500">Shortly</a>
                <div class="flex items-center gap-2">
                    <button id="theme-toggle" type="button" class="grid size-10 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:text-slate-300 dark:hover:bg-white/10" aria-label="Switch to dark theme">
                        <svg data-theme-sun class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" /></svg>
                        <svg data-theme-moon class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" /></svg>
                    </button>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:bg-white dark:text-slate-950">Log out</button>
                    </form>
                </div>
            </nav>
        </header>

        <main id="main-content" class="mx-auto w-full max-w-6xl px-5 py-10 sm:px-8">
            <div class="max-w-3xl">
                <p class="sr-only">Your account</p>
                <p class="text-sm font-semibold text-blue-600 dark:text-blue-300">Signed in as {{ auth()->user()->email }}</p>
                <h1 class="mt-2 text-4xl font-bold tracking-tight">Your links</h1>
                <p class="mt-3 text-slate-600 dark:text-slate-300">Create, inspect, and manage every link owned by your account.</p>
                @if (session('status') === 'email-verified')
                    <p class="mt-5 rounded-lg bg-green-100 px-4 py-3 text-sm font-medium text-green-900" role="status">Your email address has been verified.</p>
                @elseif (! auth()->user()->hasVerifiedEmail())
                    <div class="mt-5 rounded-lg bg-amber-100 px-4 py-3 text-sm text-amber-950" role="status">
                        Your email address is not verified. Verify your email before claiming anonymous links.
                        <a href="{{ route('verification.notice') }}" class="font-semibold underline">Verify your email</a>.
                    </div>
                @endif
            </div>

            <section class="mt-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.04]" aria-labelledby="create-heading">
                <h2 id="create-heading" class="text-xl font-bold">Create an owned link</h2>
                <form id="owner-create-form" class="mt-5 grid gap-4 lg:grid-cols-2" novalidate>
                    <div class="lg:col-span-2">
                        <label for="owner-long-url" class="text-sm font-semibold">Destination URL</label>
                        <input id="owner-long-url" name="long_url" type="url" required aria-describedby="owner-create-error" placeholder="https://example.com/a-long-page" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                    </div>
                    <div>
                        <label for="owner-custom-alias" class="text-sm font-semibold">Custom alias <span class="font-normal text-slate-500">(optional)</span></label>
                        <input id="owner-custom-alias" name="custom_alias" type="text" autocomplete="off" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                    </div>
                    <div>
                        <label for="owner-expires-at" class="text-sm font-semibold">Expiration <span class="font-normal text-slate-500">(optional)</span></label>
                        <input id="owner-expires-at" name="expires_at" type="datetime-local" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                    </div>
                    <p id="owner-create-error" class="text-sm font-semibold text-red-700 dark:text-red-300 lg:col-span-2"></p>
                    <div class="lg:col-span-2">
                        <button type="submit" class="rounded-lg bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-60">Create short link</button>
                    </div>
                </form>
            </section>

            <section id="claim-links" class="mt-8 rounded-2xl border border-cyan-200 bg-cyan-50 p-6 dark:border-cyan-300/20 dark:bg-cyan-300/[0.06]" aria-labelledby="claim-heading" hidden>
                <h2 id="claim-heading" class="text-xl font-bold">Claim a recent anonymous link</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Recent browser links stay anonymous until you explicitly add one to this account.</p>
                <form id="claim-link-form" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label for="claim-link-select" class="flex-1 text-sm font-semibold">
                        Recent link
                        <select id="claim-link-select" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 dark:border-white/15 dark:bg-slate-950"></select>
                    </label>
                    <button id="claim-link-button" type="submit" class="rounded-lg bg-slate-950 px-5 py-3 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-60 dark:bg-white dark:text-slate-950">Claim link</button>
                </form>
                <p id="claim-message" class="mt-3 text-sm" aria-live="polite"></p>
            </section>

            <section id="api-keys-panel" data-endpoint="{{ route('account.api-keys.index') }}" class="mt-10" aria-labelledby="api-keys-heading" aria-busy="true">
                <div class="max-w-3xl">
                    <h2 id="api-keys-heading" class="text-2xl font-bold">API keys</h2>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Create scoped automation keys for this verified account. New secrets are shown once.</p>
                </div>
                @if (! auth()->user()->hasVerifiedEmail())
                    <div class="mt-5 rounded-lg bg-amber-100 px-4 py-3 text-sm text-amber-950" role="status">
                        Verify your email before creating API keys.
                        <a href="{{ route('verification.notice') }}" class="font-semibold underline">Verify your email</a>.
                    </div>
                @else
                    <form id="api-key-form" class="mt-5 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.04]" novalidate>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label for="api-key-name" class="text-sm font-semibold">Key name</label>
                                <input id="api-key-name" name="name" type="text" maxlength="{{ config('url_shortener.api_keys.name_max_length') }}" required class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                            </div>
                            <div>
                                <label for="api-key-expires-at" class="text-sm font-semibold">Expiration <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="api-key-expires-at" name="expires_at" type="datetime-local" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                            </div>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-semibold">Scopes</legend>
                            <div class="mt-3 flex flex-wrap gap-3">
                                @foreach (config('url_shortener.api_keys.scopes') as $scope => $description)
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/15">
                                        <input type="checkbox" name="api_key_scopes[]" value="{{ $scope }}" class="size-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" @checked($loop->first)>
                                        <span><span class="font-semibold">{{ $scope }}</span> {{ $description }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="max-w-md">
                            <label for="api-key-password" class="text-sm font-semibold">Current password</label>
                            <input id="api-key-password" name="password" type="password" autocomplete="current-password" required class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30 dark:border-white/15 dark:bg-slate-950">
                        </div>
                        <div>
                            <button id="api-key-submit" type="submit" class="rounded-lg bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-60">Create API key</button>
                        </div>
                    </form>
                    <div id="api-key-created" class="mt-5 rounded-2xl border border-green-200 bg-green-50 p-5 dark:border-green-300/20 dark:bg-green-300/[0.06]" hidden>
                        <label for="api-key-secret" class="text-sm font-semibold text-green-950 dark:text-green-100">Copy this API key now. It will not be shown again.</label>
                        <input id="api-key-secret" type="text" readonly class="mt-2 block w-full rounded-lg border border-green-300 bg-white px-3 py-3 font-mono text-sm text-slate-950 dark:border-green-300/30">
                    </div>
                @endif
                <div class="mt-6 flex items-center justify-between gap-3">
                    <p id="api-key-status" class="text-sm text-slate-600 dark:text-slate-300" role="status" aria-live="polite">Loading API keys...</p>
                    <button id="api-key-retry" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-white/15" hidden>Retry</button>
                </div>
                <p id="api-key-empty" class="mt-6 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-600 dark:border-white/15 dark:text-slate-300" hidden>You have not created any API keys yet.</p>
                <ul id="api-key-list" class="mt-6 grid gap-5" aria-label="Your API keys"></ul>
            </section>

            <section id="owner-dashboard" data-endpoint="{{ route('account.urls.index') }}" class="mt-10" aria-labelledby="links-heading" aria-busy="true">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 id="links-heading" class="text-2xl font-bold" tabindex="-1">Owned links</h2>
                        <p id="dashboard-status" class="mt-2 text-sm text-slate-600 dark:text-slate-300" role="status" aria-live="polite">Loading your links…</p>
                    </div>
                    <button id="dashboard-retry" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-white/15" hidden>Retry</button>
                </div>
                <p id="owner-links-empty" class="mt-6 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-600 dark:border-white/15 dark:text-slate-300" hidden>You do not own any links yet. Create one above or claim a recent anonymous link.</p>
                <ul id="owner-links-list" class="mt-6 grid gap-5" aria-label="Your owned links"></ul>
                <nav id="dashboard-pagination" class="mt-6 flex items-center justify-between" aria-label="Owned links pages" hidden>
                    <button id="dashboard-previous" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-50 dark:border-white/15">Previous</button>
                    <span id="dashboard-page-label" class="text-sm font-medium" aria-live="polite"></span>
                    <button id="dashboard-next" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-50 dark:border-white/15">Next</button>
                </nav>
            </section>
        </main>
    </body>
</html>
