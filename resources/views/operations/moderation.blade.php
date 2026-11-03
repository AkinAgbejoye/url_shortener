<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moderation operations · Shortly</title>
    <link rel="stylesheet" href="{{ asset('report.css') }}">
</head>
<body class="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-white">
<a href="#moderation-content" class="sr-only focus:not-sr-only">Skip to moderation queue</a>
<main id="moderation-content" class="mx-auto max-w-6xl px-5 py-10" tabindex="-1">
    <a href="{{ route('account.operations.intelligence') }}" class="text-blue-700 underline dark:text-blue-300">Intelligence operations</a>
    <h1 class="mt-6 text-3xl font-bold">Abuse and moderation queue</h1>
    <p class="mt-3 text-slate-600 dark:text-slate-300">Sensitive report content is visible only to allowlisted verified operators. Actions are append-only audited.</p>
    @if (session('status'))<p class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4 text-green-950" role="status">{{ session('status') }}</p>@endif
    <div class="mt-8 space-y-6">
        @forelse ($reports as $report)
            <article class="rounded-xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.04]" aria-labelledby="report-{{ $report->public_id }}">
                <h2 id="report-{{ $report->public_id }}" class="text-xl font-semibold">{{ $report->public_id }}</h2>
                <dl class="mt-3 grid gap-2 sm:grid-cols-3"><div><dt class="font-semibold">Category</dt><dd>{{ $report->category }}</dd></div><div><dt class="font-semibold">Status</dt><dd>{{ $report->status }}</dd></div><div><dt class="font-semibold">Received</dt><dd><time datetime="{{ $report->created_at->toISOString() }}">{{ $report->created_at->toISOString() }}</time></dd></div></dl>
                <p class="mt-4 whitespace-pre-wrap">{{ $report->description }}</p>
                @if ($report->evidence_reference)<p class="mt-3"><span class="font-semibold">Evidence reference:</span> {{ $report->evidence_reference }}</p>@endif
                <a class="mt-4 inline-block text-blue-700 underline dark:text-blue-300" href="{{ route('account.operations.moderation.export', $report) }}">Export bounded incident record</a>
                <form method="post" action="{{ route('account.operations.moderation.action', $report) }}" class="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    <label><span class="block text-sm font-semibold">Action</span><select name="action" required class="mt-1 w-full rounded-lg border p-2 text-slate-950">@foreach (['assign', 'resolve', 'accept_appeal', 'pause_job', 'cancel_job', 'block_domain', 'suspend_account', 'resume_account', 'release_domain', 'delete_report_data'] as $action)<option value="{{ $action }}">{{ str_replace('_', ' ', ucfirst($action)) }}</option>@endforeach</select></label>
                    <label><span class="block text-sm font-semibold">Analysis ID for enforcement actions</span><input name="analysis_id" maxlength="40" class="mt-1 w-full rounded-lg border p-2 text-slate-950"></label>
                    <button class="self-end rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white dark:bg-blue-500">Apply</button>
                </form>
            </article>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.04]">No reports are awaiting review.</p>
        @endforelse
    </div>
</main>
</body>
</html>
