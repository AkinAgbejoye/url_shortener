<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Website intelligence operations - Shortly</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased dark:bg-slate-950 dark:text-white">
        <a href="#main-content" class="fixed left-4 top-4 z-50 -translate-y-24 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-950 shadow-lg transition focus:translate-y-0">Skip to main content</a>
        <header class="border-b border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 sm:px-8" aria-label="Operator navigation">
                <a href="{{ route('home') }}" class="text-xl font-bold">Shortly</a>
                <a href="{{ route('account') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-white/15">Account</a>
            </nav>
        </header>
        <main id="main-content" class="mx-auto w-full max-w-6xl px-5 py-10 sm:px-8">
            <p class="text-sm font-semibold text-blue-600 dark:text-blue-300">Restricted operator view</p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight">Website intelligence operations</h1>
            <p class="mt-3 max-w-3xl text-slate-600 dark:text-slate-300">Bounded aggregate signals only. This page never exposes destinations, page content, owner identifiers, credentials, hostnames, or exception details.</p>
            <div class="mt-6 rounded-xl border p-4 {{ $readiness['status'] === 'ready' ? 'border-green-300 bg-green-50 text-green-950' : 'border-red-300 bg-red-50 text-red-950' }}" role="status" aria-live="polite">
                <p class="font-semibold">Readiness: {{ str_replace('_', ' ', $readiness['status']) }}</p>
                <p class="mt-1 text-sm">Checked at {{ $readiness['checked_at'] }}.</p>
            </div>
            @if ($operations['signal'] !== 'fresh')
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950" role="alert">Aggregate operations signal is {{ $operations['signal'] }}. Do not use these values to accept new work until telemetry recovers.</div>
            @endif
            <section class="mt-8" aria-labelledby="dependency-heading">
                <h2 id="dependency-heading" class="text-2xl font-bold">Required dependencies</h2>
                <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.04]">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Readiness of required website intelligence dependencies</caption>
                        <thead><tr class="border-b border-slate-200 dark:border-white/10"><th class="p-3">Dependency</th><th class="p-3">Status</th></tr></thead>
                        <tbody>
                            @foreach ($readiness['checks'] as $name => $status)
                                <tr class="border-b border-slate-100 last:border-0 dark:border-white/5"><th scope="row" class="p-3 font-medium">{{ str_replace('_', ' ', $name) }}</th><td class="p-3">{{ str_replace('_', ' ', $status) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @php
                $tables = [
                    'Queue and worker capacity' => $operations['queue'],
                    'Job outcomes' => $operations['outcomes'],
                    'Blocked destination categories' => $operations['blocked_destinations'],
                    'Sanitizer and schema failures' => $operations['validation_failures'],
                    'Deletion health' => $operations['deletion'],
                    'Configured budgets' => $operations['budgets'],
                ];
            @endphp
            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                @foreach ($tables as $title => $values)
                    <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-white/[0.04]" aria-labelledby="table-{{ $loop->index }}">
                        <h2 id="table-{{ $loop->index }}" class="text-xl font-bold">{{ $title }}</h2>
                        <table class="mt-4 w-full text-left text-sm">
                            <caption class="sr-only">{{ $title }} aggregate values</caption>
                            <tbody>
                                @foreach ($values as $name => $value)
                                    <tr class="border-b border-slate-100 last:border-0 dark:border-white/5"><th scope="row" class="py-3 pr-4 font-medium">{{ str_replace('_', ' ', $name) }}</th><td class="py-3 text-right tabular-nums">{{ number_format($value) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endforeach
            </div>
        </main>
    </body>
</html>
