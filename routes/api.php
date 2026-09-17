<?php

use App\Http\Controllers\Api\UrlController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/urls', [UrlController::class, 'store'])
    ->middleware('throttle:10,1');
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});
