<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report abuse · Shortly</title>
    <link rel="stylesheet" href="{{ asset('report.css') }}">
</head>
<body class="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-white">
<main class="mx-auto max-w-2xl px-5 py-10">
    <a href="{{ route('home') }}" class="text-blue-700 underline dark:text-blue-300">Back to Shortly</a>
    <h1 class="mt-6 text-3xl font-bold">Report harmful analysis activity</h1>
    <p class="mt-3 text-slate-600 dark:text-slate-300">Do not include passwords, credentials, raw page bodies, or sensitive query strings. We return the same acknowledgement whether or not a reference matches our records.</p>

    @if (session('status'))
        <p class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4 text-green-950" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 text-red-950" role="alert">
            <p class="font-semibold">Please correct the highlighted fields.</p>
            <ul class="mt-2 list-disc pl-6">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="post" action="{{ route('abuse-reports.store') }}" class="mt-8 space-y-6">
        @csrf
        <div><label for="category" class="block font-semibold">Category</label><select id="category" name="category" required class="mt-2 w-full rounded-lg border p-3 text-slate-950"><option value="">Select one</option>@foreach (['phishing', 'malware', 'privacy', 'impersonation', 'copyright', 'other'] as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></div>
        <div><label for="subject_reference" class="block font-semibold">Analysis or domain reference (optional)</label><input id="subject_reference" name="subject_reference" value="{{ old('subject_reference') }}" maxlength="200" class="mt-2 w-full rounded-lg border p-3 text-slate-950"></div>
        <div><label for="description" class="block font-semibold">What happened?</label><textarea id="description" name="description" required minlength="20" maxlength="2000" rows="7" class="mt-2 w-full rounded-lg border p-3 text-slate-950">{{ old('description') }}</textarea></div>
        <div><label for="evidence_reference" class="block font-semibold">Bounded evidence reference (optional)</label><textarea id="evidence_reference" name="evidence_reference" maxlength="500" rows="3" class="mt-2 w-full rounded-lg border p-3 text-slate-950">{{ old('evidence_reference') }}</textarea></div>
        <div class="flex gap-3"><input id="contact_consent" type="checkbox" name="contact_consent" value="1" @checked(old('contact_consent'))><label for="contact_consent">I consent to being contacted about this report.</label></div>
        <div><label for="reporter_email" class="block font-semibold">Contact email (only used with consent)</label><input id="reporter_email" type="email" name="reporter_email" value="{{ old('reporter_email') }}" maxlength="254" class="mt-2 w-full rounded-lg border p-3 text-slate-950"></div>
        <button class="rounded-lg bg-blue-700 px-5 py-3 font-semibold text-white">Submit report</button>
    </form>
</main>
</body>
</html>
