<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AbuseReportController extends Controller
{
    public function create(): View
    {
        return view('abuse-report');
    }

    public function store(Request $request, ModerationService $moderation): RedirectResponse
    {
        $input = $request->validate([
            'category' => ['required', 'in:phishing,malware,privacy,impersonation,copyright,other'],
            'subject_reference' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'contact_consent' => ['nullable', 'boolean'],
            'reporter_email' => ['nullable', 'email:rfc', 'max:254', 'required_if:contact_consent,1'],
        ]);
        $reporter = $request->user();
        $moderation->report($reporter instanceof User ? $reporter : null, $input);

        return back()->with('status', 'Thanks. Your report was received for review.');
    }
}
