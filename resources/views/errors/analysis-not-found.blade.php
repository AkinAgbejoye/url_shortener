<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Analysis not found · Shortly</title>
        <link rel="stylesheet" href="{{ asset('report.css') }}">
    </head>
    <body class="grid min-h-screen place-items-center bg-slate-50 p-6 text-slate-950 dark:bg-slate-950 dark:text-white">
        <main class="max-w-lg rounded-xl border border-slate-200 bg-white p-8 text-center dark:border-white/10 dark:bg-white/[0.04]">
            <h1 class="text-2xl font-bold">Analysis not found</h1>
            <p class="mt-3 text-slate-600 dark:text-slate-300">The requested analysis is unavailable.</p>
            <a href="{{ route('account') }}" class="mt-6 inline-block font-semibold text-blue-700 underline dark:text-blue-300">Return to your account</a>
        </main>
    </body>
</html>
