<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnalysisState;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Exceptions\AnalysisConflict;
use App\Exceptions\AnalysisNotFound;
use App\Exceptions\AnalysisQuotaExceeded;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalysisRequest;
use App\Jobs\DeleteAnalysis;
use App\Models\AnalysisJob;
use App\Models\User;
use App\Services\AnalysisJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(25, max(1, $request->integer('per_page', 10)));
        $page = max(1, $request->integer('page', 1));
        $jobs = AnalysisJob::query()->ownedBy($this->user($request))->latest('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => array_map($this->serialize(...), $jobs->items()),
            'meta' => ['current_page' => $jobs->currentPage(), 'last_page' => max(1, $jobs->lastPage()), 'per_page' => $jobs->perPage(), 'total' => $jobs->total()],
        ]);
    }

    public function store(StoreAnalysisRequest $request, AnalysisJobService $service): JsonResponse
    {
        try {
            $result = $service->create(
                $this->user($request),
                (string) $request->validated('url'),
                (string) $request->validated('authorization_attestation'),
                array_values($request->validated('user_context', [])),
                (string) $request->validated('idempotency_key'),
            );
        } catch (AcquisitionPolicyViolation) {
            return $this->error($request, 'validation_failed', 'The requested URL is not eligible for analysis.', 422);
        } catch (AnalysisConflict $exception) {
            return $this->error($request, 'conflict', $exception->getMessage(), 409);
        } catch (AnalysisQuotaExceeded $exception) {
            return $this->error($request, 'rate_limited', $exception->getMessage(), 429, ['Retry-After' => '5']);
        }

        return response()->json($this->serialize($result['job']), 202, ['Idempotent-Replayed' => $result['replayed'] ? 'true' : 'false']);
    }

    public function show(Request $request, string $analysisId): JsonResponse
    {
        return response()->json($this->serialize($this->owned($request, $analysisId)));
    }

    public function cancel(Request $request, string $analysisId): JsonResponse
    {
        $job = $this->owned($request, $analysisId);
        if ($job->state->active() && $job->state !== AnalysisState::CancelRequested) {
            $job->forceFill(['state' => AnalysisState::CancelRequested, 'cancel_requested_at' => now()])->save();
        }

        return response()->json($this->serialize($job->refresh()), 202);
    }

    public function destroy(Request $request, string $analysisId): JsonResponse
    {
        $job = $this->owned($request, $analysisId);
        $snapshot = $this->serialize($job);
        if ($job->state->active()) {
            $job->forceFill(['state' => AnalysisState::CancelRequested, 'cancel_requested_at' => now()])->save();
            $snapshot = $this->serialize($job);
        }
        DeleteAnalysis::dispatch($job->getKey())->onQueue('intelligence');

        return response()->json($snapshot, 202);
    }

    public function report(Request $request, string $analysisId): JsonResponse
    {
        $this->owned($request, $analysisId);

        return $this->error($request, 'conflict', 'The analysis report is not available.', 409);
    }

    private function owned(Request $request, string $publicId): AnalysisJob
    {
        return AnalysisJob::query()->ownedBy($this->user($request))->where('public_id', $publicId)->first()
            ?? throw new AnalysisNotFound;
    }

    /** @return array<string, mixed> */
    private function serialize(AnalysisJob $job): array
    {
        return [
            'id' => $job->public_id,
            'state' => $job->state->value,
            'submitted_origin' => $job->submitted_origin,
            'created_at' => $job->created_at->toISOString(),
            'updated_at' => $job->updated_at->toISOString(),
            'expires_at' => $job->expires_at->toISOString(),
            'safe_failure_category' => $job->safe_failure_category,
        ];
    }

    /** @param array<string, string> $headers */
    private function error(Request $request, string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'request_id' => (string) $request->attributes->get('request_id')]], $status, $headers);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
