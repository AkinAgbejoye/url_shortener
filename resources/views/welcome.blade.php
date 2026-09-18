<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Create short, shareable links in seconds.">
        <title>Shortly — Short links, made simple</title>
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
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased transition-colors dark:bg-slate-950 dark:text-white">
        <a href="#main-content" class="fixed left-4 top-4 z-50 -translate-y-24 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-950 shadow-lg transition focus:translate-y-0 focus:outline-2 focus:outline-offset-2 focus:outline-blue-400">
            Skip to main content
        </a>
        <div class="relative isolate min-h-screen overflow-hidden">
            <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[32rem] bg-[radial-gradient(circle_at_top,rgba(59,130,246,0.14),transparent_62%)] dark:bg-[radial-gradient(circle_at_top,rgba(59,130,246,0.2),transparent_62%)]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -left-36 top-64 -z-10 h-80 w-80 rounded-full bg-cyan-400/10 blur-3xl" aria-hidden="true"></div>

            <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-5 py-6 sm:px-8 lg:px-10">
                <a href="/" class="inline-flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-400" aria-label="Shortly home">
                    <span class="grid size-10 place-items-center rounded-xl bg-blue-500 shadow-lg shadow-blue-500/20" aria-hidden="true">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                        </svg>
                    </span>
                    <span class="text-xl font-bold tracking-tight">Shortly</span>
                </a>
                <div class="flex items-center gap-1 sm:gap-3">
                    <button id="theme-toggle" type="button" class="grid size-10 place-items-center rounded-lg text-slate-600 transition hover:bg-slate-200 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Switch to dark theme">
                        <svg data-theme-sun class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" /></svg>
                        <svg data-theme-moon class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" /></svg>
                    </button>
                    <a href="https://github.com/AkinAgbejoye/url_shortener" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-400 dark:text-slate-300 dark:hover:text-white" rel="noreferrer">View on GitHub</a>
                </div>
            </header>

            <main id="main-content" class="mx-auto flex w-full max-w-6xl flex-col items-center px-5 pb-16 pt-14 text-center sm:px-8 sm:pt-20 lg:px-10 lg:pt-24" tabindex="-1">
                <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-400/20 bg-blue-400/10 px-3 py-1 text-sm font-medium text-blue-200">
                    <span class="size-1.5 rounded-full bg-cyan-300" aria-hidden="true"></span>
                    Fast, reliable, and easy to use
                </div>
                <h1 class="max-w-4xl text-balance text-4xl font-bold tracking-tight sm:text-6xl lg:text-7xl">
                    Turn long links into
                    <span class="bg-gradient-to-r from-blue-400 to-cyan-300 bg-clip-text text-transparent">short connections.</span>
                </h1>
                <p class="mt-6 max-w-2xl text-pretty text-base leading-7 text-slate-600 sm:text-lg dark:text-slate-300">Paste a long URL and get a clean, shareable link in seconds. No account required.</p>

                <section class="mt-10 w-full max-w-3xl" aria-labelledby="shortener-heading">
                    <h2 id="shortener-heading" class="sr-only">Shorten a URL</h2>
                    <form id="shortener-form" class="rounded-2xl border border-slate-200 bg-white/80 p-3 shadow-2xl shadow-slate-300/30 backdrop-blur sm:flex sm:items-end sm:gap-3 sm:p-4 dark:border-white/10 dark:bg-white/[0.07] dark:shadow-black/20" data-endpoint="/api/v1/urls" aria-describedby="url-requirements" novalidate>
                        <div class="flex-1 text-left">
                            <label for="long-url" class="sr-only">Long URL</label>
                            <div data-input-shell class="flex items-center gap-3 rounded-xl bg-white px-4 ring-1 ring-inset ring-slate-200 focus-within:ring-2 focus-within:ring-blue-500">
                                <svg class="size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20" />
                                </svg>
                                <input id="long-url" name="long_url" type="url" inputmode="url" autocomplete="url" placeholder="Paste your long URL here" class="min-w-0 flex-1 bg-transparent py-4 text-base text-slate-950 outline-none placeholder:text-slate-400" aria-describedby="url-requirements long-url-error" required>
                            </div>
                            <p id="long-url-error" class="mt-2 hidden text-sm font-medium text-red-300" role="alert"></p>
                        </div>
                        <button id="shorten-button" type="submit" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-500 px-6 py-4 text-base font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:bg-blue-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-300 disabled:cursor-wait disabled:opacity-70 sm:mt-0 sm:w-auto">
                            <span data-button-label>Shorten URL</span>
                            <svg data-button-arrow class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                            <svg data-button-spinner class="hidden size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                                <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z" />
                            </svg>
                        </button>
                    </form>
                    <p id="shortener-status" class="sr-only" role="status" aria-live="polite"></p>
                    <p id="url-requirements" class="mt-4 text-sm text-slate-500 dark:text-slate-400">Only HTTP and HTTPS links are supported.</p>
                    <div id="short-url-result" class="mt-8 outline-none" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>

                    <section id="recent-links" class="mt-10 text-left" aria-labelledby="recent-links-heading" hidden>
                        <div class="flex items-center justify-between gap-4">
                            <h2 id="recent-links-heading" class="text-lg font-semibold text-slate-950 dark:text-white">Recent links</h2>
                            <button id="clear-history" type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 transition hover:bg-slate-200 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white">Clear history</button>
                        </div>
                        <ul id="recent-links-list" class="mt-3 space-y-3"></ul>
                    </section>
                </section>

                <div class="mt-16 grid w-full max-w-3xl gap-4 text-left sm:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-white/70 p-5 dark:border-white/10 dark:bg-white/[0.04]"><p class="text-sm font-semibold text-slate-950 dark:text-white">Instant</p><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">Generate compact links without waiting or signing up.</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white/70 p-5 dark:border-white/10 dark:bg-white/[0.04]"><p class="text-sm font-semibold text-slate-950 dark:text-white">Resilient</p><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">Database-backed redirects keep working through cache failures.</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white/70 p-5 dark:border-white/10 dark:bg-white/[0.04]"><p class="text-sm font-semibold text-slate-950 dark:text-white">Private</p><p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">No tracking scripts and no account needed to shorten a link.</p></div>
                </div>
            </main>

            <footer class="mx-auto w-full max-w-6xl px-5 pb-8 text-center text-sm text-slate-500 sm:px-8 lg:px-10 dark:text-slate-500">Built with Laravel and a focus on reliable redirects.</footer>
        </div>
    </body>
</html>
