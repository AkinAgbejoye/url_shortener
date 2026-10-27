<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisState;
use App\Exceptions\AnalysisNotFound;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisReportPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AnalysisReportController extends Controller
{
    public function show(Request $request, string $analysisId, AnalysisReportPresenter $presenter): View|Response
    {
        $job = $this->owned($request, $analysisId);
        $status = $this->status($job);
        $data = $status === 200 ? $presenter->present($job) : null;
        $observations = $status === 200 ? $job->observations()->with('pageFetch')->orderBy('id')->get() : collect();

        return response()->view('analysis-report', compact('job', 'data', 'observations', 'status'), $status)
            ->withHeaders($this->securityHeaders());
    }

    public function download(Request $request, string $analysisId, AnalysisReportPresenter $presenter): JsonResponse
    {
        $job = $this->owned($request, $analysisId);
        $status = $this->status($job);
        if ($status !== 200) {
            return response()->json(['error' => $status === 410 ? 'Report expired.' : 'Report unavailable.'], $status, $this->securityHeaders());
        }

        return response()->json($presenter->present($job), 200, [
            ...$this->securityHeaders(),
            'Content-Disposition' => 'attachment; filename="'.$job->public_id.'-report.json"',
        ]);
    }

    private function owned(Request $request, string $publicId): AnalysisJob
    {
        /** @var User $owner */
        $owner = $request->user();

        return AnalysisJob::query()->ownedBy($owner)->where('public_id', $publicId)->first()
            ?? throw new AnalysisNotFound;
    }

    private function status(AnalysisJob $job): int
    {
        if ($job->expires_at->isPast() || $job->state === AnalysisState::Expired) {
            return 410;
        }

        return in_array($job->state, [AnalysisState::Completed, AnalysisState::PartiallyCompleted], true) && $job->report()->exists()
            ? 200
            : 409;
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
