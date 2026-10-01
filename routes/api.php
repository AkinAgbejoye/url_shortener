<?php

use App\Http\Controllers\Api\UrlController;
use App\Http\Controllers\Api\UrlManagementController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/urls', [UrlController::class, 'store'])
    ->middleware('throttle:10,1');
Route::prefix('/v1/urls/{shortCode}')
    ->middleware('throttle:30,1')
    ->controller(UrlManagementController::class)
    ->group(function (): void {
        Route::get('/', 'show');
        Route::get('/analytics', 'analytics');
        Route::post('/claim', 'claim')->middleware(['auth', 'verified']);
        Route::patch('/', 'update');
        Route::post('/disable', 'disable');
        Route::post('/enable', 'enable');
        Route::delete('/', 'destroy');
    });
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
