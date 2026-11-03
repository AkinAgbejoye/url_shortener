<?php

use App\Http\Controllers\Api\AnalysisBatchController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\UrlController;
use App\Http\Controllers\Api\UrlManagementController;
use App\Http\Controllers\IntelligenceProbeController;
use App\Http\Controllers\OwnerUrlController;
use Illuminate\Support\Facades\Route;

Route::prefix('/v1/urls/{shortCode}')
    ->middleware(['api-key.authenticate', 'throttle:api-key.requests'])
    ->controller(UrlManagementController::class)
    ->group(function (): void {
        Route::get('/', 'show')->middleware('api-key.scope:urls:read');
        Route::get('/analytics', 'analytics')->middleware('api-key.scope:analytics:read');
        Route::post('/claim', 'claim')->middleware(['api-key.scope:urls:write', 'auth', 'verified']);
        Route::patch('/', 'update')->middleware('api-key.scope:urls:write');
        Route::post('/disable', 'disable')->middleware('api-key.scope:urls:write');
        Route::post('/enable', 'enable')->middleware('api-key.scope:urls:write');
        Route::delete('/', 'destroy')->middleware('api-key.scope:urls:write');
    });
Route::prefix('/v1/urls')
    ->middleware(['api-key.authenticate', 'throttle:api-key.requests'])
    ->group(function (): void {
        Route::get('/', [OwnerUrlController::class, 'index'])
            ->middleware('api-key.scope:urls:read,required');
        Route::post('/', [UrlController::class, 'store'])
            ->middleware('api-key.scope:urls:write');
    });
Route::prefix('/v1/analyses')
    ->middleware(['api-key.authenticate', 'throttle:api-key.requests', 'auth', 'verified'])
    ->controller(AnalysisController::class)
    ->group(function (): void {
        Route::get('/', 'index')->middleware('api-key.scope:analyses:read,required');
        Route::post('/', 'store')->middleware('api-key.scope:analyses:write,required');
        Route::get('/{analysisId}', 'show')->middleware('api-key.scope:analyses:read,required');
        Route::delete('/{analysisId}', 'destroy')->middleware('api-key.scope:analyses:write,required');
        Route::post('/{analysisId}/cancellation', 'cancel')->middleware('api-key.scope:analyses:write,required');
        Route::get('/{analysisId}/report', 'report')->middleware('api-key.scope:analyses:read,required');
        Route::post('/{analysisId}/regeneration', 'regenerate')->middleware('api-key.scope:analyses:write,required');
    });
Route::prefix('/v1/analysis-batches')
    ->middleware(['api-key.authenticate', 'throttle:api-key.requests', 'auth', 'verified'])
    ->controller(AnalysisBatchController::class)
    ->group(function (): void {
        Route::post('/preview', 'preview')->middleware('api-key.scope:analyses:write,required');
        Route::post('/', 'store')->middleware('api-key.scope:analyses:write,required');
        Route::post('/exports', 'export')->middleware('api-key.scope:analyses:read,required');
        Route::get('/{batchId}', 'show')->middleware('api-key.scope:analyses:read,required');
        Route::post('/{batchId}/cancellation', 'cancel')->middleware('api-key.scope:analyses:write,required');
        Route::post('/{batchId}/retry', 'retry')->middleware('api-key.scope:analyses:write,required');
        Route::delete('/{batchId}', 'destroy')->middleware('api-key.scope:analyses:write,required');
    });
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
Route::get('/intelligence/liveness', [IntelligenceProbeController::class, 'liveness']);
Route::get('/intelligence/readiness', [IntelligenceProbeController::class, 'readiness']);
