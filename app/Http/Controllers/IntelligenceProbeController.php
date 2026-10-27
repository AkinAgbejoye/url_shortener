<?php

namespace App\Http\Controllers;

use App\Services\IntelligenceReadinessService;
use Illuminate\Http\JsonResponse;

class IntelligenceProbeController extends Controller
{
    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'alive']);
    }

    public function readiness(IntelligenceReadinessService $readiness): JsonResponse
    {
        $snapshot = $readiness->snapshot();

        return response()->json($snapshot, $snapshot['status'] === 'ready' ? 200 : 503);
    }
}
