<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Analysis report · Shortly</title>
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-white">
        <a href="#report-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-slate-950">Skip to report</a>
        <header class="border-b border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950">
            <nav class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-5 sm:px-8" aria-label="Report navigation">
                <a href="{{ route('account') }}" class="font-semibold text-blue-700 underline-offset-4 hover:underline dark:text-blue-300">Shortly account</a>
                @if ($status === 200)
                    <a href="{{ route('account.analyses.report.download', $job->public_id) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-white/20">Export JSON</a>
                @endif
            </nav>
        </header>
        <main id="report-content" class="mx-auto max-w-5xl px-5 py-10 sm:px-8" tabindex="-1">
            <p class="text-sm font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-300">Evidence-backed page report</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight">Analysis {{ $job->public_id }}</h1>
            <p class="mt-3 text-slate-600 dark:text-slate-300">Source origin: {{ $job->submitted_origin }}</p>

            @if ($status === 410)
                <section class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-6 text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100" aria-labelledby="expired-heading">
                    <h2 id="expired-heading" class="text-xl font-semibold">Report expired</h2>
                    <p class="mt-2">The retained evidence has passed its expiry boundary and is no longer available.</p>
                </section>
            @elseif ($status === 409)
                <section class="mt-8 rounded-xl border border-slate-300 bg-white p-6 dark:border-white/15 dark:bg-white/[0.04]" aria-labelledby="pending-heading" role="status">
                    <h2 id="pending-heading" class="text-xl font-semibold">Report unavailable</h2>
                    <p class="mt-2 text-slate-600 dark:text-slate-300">Current analysis state: {{ str_replace('_', ' ', $job->state->value) }}.</p>
                </section>
            @else
                <dl class="mt-8 grid gap-4 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-3 dark:border-white/10 dark:bg-white/[0.04]">
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Schema</dt><dd class="mt-1 font-semibold">{{ $data['schema_version'] }}</dd></div>
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Generated</dt><dd class="mt-1 font-semibold"><time datetime="{{ $data['generated_at'] }}">{{ $data['generated_at'] }}</time></dd></div>
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Observations</dt><dd class="mt-1 font-semibold">{{ count($data['observations']) }}</dd></div>
                </dl>

                <section class="mt-10" aria-labelledby="observations-heading">
                    <h2 id="observations-heading" class="text-2xl font-bold">Source observations</h2>
                    <p class="mt-2 text-slate-600 dark:text-slate-300">Each statement is paired with its captured source, location, timestamp, and excerpt. No interpretation is added.</p>
                    <ol class="mt-6 grid gap-5">
                        @forelse ($observations as $observation)
                            <li class="rounded-xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.04]">
                                <h3 class="font-semibold">{{ str_replace('_', ' ', ucfirst($observation->kind)) }}</h3>
                                <p class="mt-2">{{ $observation->statement }}</p>
                                <dl class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                                    <div><dt class="font-semibold text-slate-800 dark:text-slate-100">Source location</dt><dd>{{ $observation->source_location }}</dd></div>
                                    <div><dt class="font-semibold text-slate-800 dark:text-slate-100">Observed</dt><dd><time datetime="{{ $observation->observed_at->toISOString() }}">{{ $observation->observed_at->toISOString() }}</time></dd></div>
                                    <div class="sm:col-span-2"><dt class="font-semibold text-slate-800 dark:text-slate-100">Content hash</dt><dd class="break-all font-mono">sha256:{{ $observation->content_hash }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="font-semibold text-slate-800 dark:text-slate-100">Source URL</dt><dd class="break-all">{{ $observation->pageFetch->source_url }}</dd></div>
                                    <div class="sm:col-span-2"><dt class="font-semibold text-slate-800 dark:text-slate-100">Evidence excerpt</dt><dd>{{ $observation->excerpt }}</dd></div>
                                </dl>
                            </li>
                        @empty
                            <li class="rounded-xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.04]">No reportable observations were found.</li>
                        @endforelse
                    </ol>
                </section>

                @if ($data['user_supplied_context'] !== [])
                    <section class="mt-10" aria-labelledby="context-heading">
                        <h2 id="context-heading" class="text-2xl font-bold">User-supplied context</h2>
                        <ul class="mt-4 list-disc space-y-2 pl-6">
                            @foreach ($data['user_supplied_context'] as $context)
                                <li>{{ $context['statement'] }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endif
        </main>
    </body>
</html>
