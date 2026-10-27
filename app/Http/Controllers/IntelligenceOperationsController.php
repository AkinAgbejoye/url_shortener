<?php

namespace App\Http\Controllers;

use App\Services\IntelligenceOperationsService;
use App\Services\IntelligenceReadinessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class IntelligenceOperationsController extends Controller
{
    public function show(
        IntelligenceReadinessService $readiness,
        IntelligenceOperationsService $operations,
    ): View {
        return view('operations.intelligence', [
            'readiness' => $readiness->snapshot(),
            'operations' => $operations->snapshot(),
        ]);
    }

    public function data(
        IntelligenceReadinessService $readiness,
        IntelligenceOperationsService $operations,
    ): JsonResponse {
        return response()->json([
            'readiness' => $readiness->snapshot(),
            'operations' => $operations->snapshot(),
        ]);
    }
}
