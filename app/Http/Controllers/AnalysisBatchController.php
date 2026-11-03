<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAnalysisBatch;
use App\Models\AnalysisBatch;
use App\Models\User;
use App\Services\AnalysisBatchService;
use App\Services\AnalysisExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisBatchController extends Controller
{
    public function index(Request $request): View
    {
        return view('analysis-batches', ['batches' => AnalysisBatch::query()->ownedBy($this->owner($request))->with('rows')->latest()->limit(50)->get()]);
    }

    public function preview(Request $request, AnalysisBatchService $service): RedirectResponse
    {
        $input = $this->manifest($request);
        try {
            $preview = $service->preview($input['manifest'], $input['format']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()]);
        }

        return back()->with('preview', $preview)->withInput();
    }

    public function store(Request $request, AnalysisBatchService $service): RedirectResponse
    {
        $input = $this->manifest($request);
        try {
            $batch = $service->create($this->owner($request), $input['manifest'], $input['format']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Batch '.$batch->public_id.' queued.');
    }

    public function control(Request $request, string $batchId): RedirectResponse
    {
        $batch = AnalysisBatch::query()->ownedBy($this->owner($request))->where('public_id', $batchId)->firstOrFail();
        $action = $request->validate(['action' => ['required', Rule::in(['cancel', 'retry', 'delete'])]])['action'];
        if ($action === 'delete') {
            $batch->delete();

            return back()->with('status', 'Batch metadata deleted.');
        }
        if ($action === 'cancel' && in_array($batch->state, ['queued', 'running'], true)) {
            $batch->forceFill(['cancel_requested_at' => now(), 'state' => 'cancelled', 'completed_at' => now()])->save();
        }
        if ($action === 'retry' && in_array($batch->state, ['failed', 'partially_completed', 'cancelled'], true)) {
            $failed = $batch->rows()->where('status', 'failed')->count();
            $batch->rows()->where('status', 'failed')->delete();
            $batch->forceFill(['state' => 'queued', 'cancel_requested_at' => null, 'completed_at' => null, 'processed_rows' => max(0, $batch->processed_rows - $failed), 'failed_rows' => 0])->save();
            ProcessAnalysisBatch::dispatch($batch->id)->onQueue('intelligence');
        }

        return back()->with('status', 'Batch control updated.');
    }

    public function export(Request $request, AnalysisExportService $exports): StreamedResponse
    {
        $input = $request->validate(['format' => ['required', Rule::in(['csv', 'json'])], 'analysis_ids' => ['required', 'array', 'max:100'], 'analysis_ids.*' => ['string', 'max:40']]);

        return $exports->stream($this->owner($request), $input['analysis_ids'], $input['format']);
    }

    private function manifest(Request $request): array
    {
        return $request->validate(['format' => ['required', Rule::in(['csv', 'json'])], 'manifest' => ['required', 'string', 'max:100000']]);
    }

    private function owner(Request $request): User
    {
        /** @var User $owner */
        $owner = $request->user();

        return $owner;
    }
}
