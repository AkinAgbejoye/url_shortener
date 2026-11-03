<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AnalysisNotFound;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAnalysisBatch;
use App\Models\AnalysisBatch;
use App\Models\User;
use App\Services\AnalysisBatchService;
use App\Services\AnalysisExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisBatchController extends Controller
{
    public function preview(Request $request, AnalysisBatchService $service): JsonResponse
    {
        $input = $this->manifest($request);

        try {
            return response()->json($service->preview($input['manifest'], $input['format']));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]], 422);
        }
    }

    public function store(Request $request, AnalysisBatchService $service): JsonResponse
    {
        $input = $this->manifest($request);

        try {
            $batch = $service->create($this->user($request), $input['manifest'], $input['format']);
        } catch (RuntimeException $exception) {
            $status = $exception->getMessage() === 'batch_quota_full' ? 429 : 422;

            return response()->json(['error' => ['code' => $status === 429 ? 'rate_limited' : 'validation_failed', 'message' => $exception->getMessage()]], $status);
        }

        return response()->json($this->serialize($batch), 202);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        return response()->json($this->serialize($this->owned($request, $batchId)->load('rows')));
    }

    public function cancel(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->owned($request, $batchId);
        if (in_array($batch->state, ['queued', 'running'], true)) {
            $batch->forceFill(['cancel_requested_at' => now(), 'state' => 'cancelled', 'completed_at' => now()])->save();
        }

        return response()->json($this->serialize($batch->refresh()), 202);
    }

    public function retry(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->owned($request, $batchId);
        if (! in_array($batch->state, ['failed', 'partially_completed', 'cancelled'], true)) {
            return response()->json(['error' => ['code' => 'conflict', 'message' => 'The batch cannot be retried in its current state.']], 409);
        }
        $failed = $batch->rows()->where('status', 'failed')->count();
        $batch->rows()->where('status', 'failed')->delete();
        $batch->forceFill([
            'state' => 'queued',
            'cancel_requested_at' => null,
            'completed_at' => null,
            'processed_rows' => max(0, $batch->processed_rows - $failed),
            'failed_rows' => 0,
        ])->save();
        ProcessAnalysisBatch::dispatch($batch->id)->onQueue('intelligence');

        return response()->json($this->serialize($batch->refresh()), 202);
    }

    public function destroy(Request $request, string $batchId): JsonResponse
    {
        $this->owned($request, $batchId)->delete();

        return response()->json(null, 204);
    }

    public function export(Request $request, AnalysisExportService $exports): StreamedResponse
    {
        $input = $request->validate([
            'format' => ['required', Rule::in(['csv', 'json'])],
            'analysis_ids' => ['required', 'array', 'max:100'],
            'analysis_ids.*' => ['string', 'max:40'],
        ]);

        return $exports->stream($this->user($request), $input['analysis_ids'], $input['format']);
    }

    /** @return array{format: string, manifest: string} */
    private function manifest(Request $request): array
    {
        return $request->validate([
            'format' => ['required', Rule::in(['csv', 'json'])],
            'manifest' => ['required', 'string', 'max:100000'],
        ]);
    }

    private function owned(Request $request, string $batchId): AnalysisBatch
    {
        return AnalysisBatch::query()->ownedBy($this->user($request))->where('public_id', $batchId)->first()
            ?? throw new AnalysisNotFound;
    }

    /** @return array<string, mixed> */
    private function serialize(AnalysisBatch $batch): array
    {
        return [
            'id' => $batch->public_id,
            'state' => $batch->state,
            'format' => $batch->format,
            'progress' => [
                'total' => $batch->total_rows,
                'processed' => $batch->processed_rows,
                'created' => $batch->successful_rows,
                'failed' => $batch->failed_rows,
            ],
            'rows' => $batch->relationLoaded('rows') ? $batch->rows->map(fn ($row): array => [
                'row_number' => $row->row_number,
                'status' => $row->status,
                'safe_error' => $row->safe_error,
                'analysis_id' => $row->analysisJob?->public_id,
            ])->values() : [],
            'expires_at' => $batch->expires_at->toISOString(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
