<?php

namespace App\Http\Controllers;

use App\Models\AbuseReport;
use App\Models\AnalysisJob;
use App\Models\ModerationAuditEvent;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function index(): View
    {
        return view('operations.moderation', [
            'reports' => AbuseReport::query()->latest()->limit(50)->get(),
        ]);
    }

    public function action(Request $request, AbuseReport $abuseReport, ModerationService $moderation): RedirectResponse
    {
        $input = $request->validate([
            'action' => ['required', 'in:assign,resolve,accept_appeal,pause_job,cancel_job,block_domain,suspend_account,resume_account,release_domain,delete_report_data'],
            'analysis_id' => ['nullable', 'required_if:action,pause_job,cancel_job,block_domain,suspend_account,resume_account,release_domain', 'string', 'max:40'],
        ]);
        $job = isset($input['analysis_id'])
            ? AnalysisJob::query()->where('public_id', $input['analysis_id'])->firstOrFail()
            : null;
        /** @var User $operator */
        $operator = $request->user();
        $moderation->act($operator, $abuseReport, $input['action'], $job);

        return back()->with('status', 'Moderation action recorded.');
    }

    public function export(AbuseReport $abuseReport): JsonResponse
    {
        $subjectHash = hash_hmac('sha256', $abuseReport->public_id, (string) config('app.key'));

        return response()->json([
            'report_id' => $abuseReport->public_id,
            'category' => $abuseReport->category,
            'status' => $abuseReport->status,
            'received_at' => $abuseReport->created_at->toISOString(),
            'resolved_at' => $abuseReport->resolved_at?->toISOString(),
            'actions' => ModerationAuditEvent::query()
                ->where('subject_type', 'abuse_report')->where('subject_hash', $subjectHash)
                ->orderBy('id')->get(['public_id', 'action', 'safe_metadata', 'occurred_at']),
        ], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'attachment; filename="'.$abuseReport->public_id.'-incident.json"',
        ]);
    }
}
